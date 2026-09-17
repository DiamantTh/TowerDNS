<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Mezzio\Csrf\CsrfGuardInterface;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Template\TemplateRendererInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Application\Exception\AuthorizationException;
use TowerDNS\Application\Repository\AccountRepositoryInterface;
use TowerDNS\Application\Services\AccountMembershipManagementService;
use TowerDNS\Application\Services\AuditLogService;
use TowerDNS\Application\Services\AccountOwnershipService;
use TowerDNS\Application\Services\PermissionService;
use TowerDNS\Domain\Account\TeamRole;
use TowerDNS\Domain\Auth\User;

/**
 * Account management: list, create, edit, delete, manage members.
 *
 * Routes handled (all behind RequireAuthMiddleware):
 *   GET  /accounts               → list all accounts for the current user
 *   POST /accounts               → create a new account
 *   GET  /accounts/{id}          → edit form
 *   POST /accounts/{id}          → update name / deactivate
 *   GET  /accounts/{id}/members  → membership list
 *   POST /accounts/{id}/members  → invite or remove a member
 */
final readonly class AccountHandler implements RequestHandlerInterface
{
    public function __construct(
        private TemplateRendererInterface            $renderer,
        private AccountRepositoryInterface           $accounts,
        private PermissionService                    $permissions,
        private AuditLogService                      $audit,
        private AccountMembershipManagementService   $memberships,
        private AccountOwnershipService              $ownership,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        if (str_ends_with($path, '/ownership')) {
            return $this->handleOwnershipPost($request);
        }

        // /accounts/{id}/members
        if (str_ends_with($path, '/members')) {
            return $request->getMethod() === 'POST'
                ? $this->handleMembersPost($request)
                : $this->handleMembersGet($request);
        }

        $id = $request->getAttribute('id');

        // /accounts/{id}
        if ($id !== null) {
            return $request->getMethod() === 'POST'
                ? $this->handleEditPost($request, (int) $id)
                : $this->handleEditGet($request, (int) $id);
        }

        // /accounts
        return $request->getMethod() === 'POST'
            ? $this->handleListPost($request)
            : $this->handleListGet($request);
    }

    // ── GET /accounts ─────────────────────────────────────────────────────────

    private function handleListGet(ServerRequestInterface $request): ResponseInterface
    {
        /** @var User $user */
        $user = $request->getAttribute(User::class);

        /** @var CsrfGuardInterface $guard */
        $guard     = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $csrfToken = $guard->generateToken();

        $accounts   = $this->accounts->findByUserId($user->id);
        $flashError = $request->getQueryParams()['error'] ?? null;

        return new HtmlResponse(
            $this->renderer->render('app::accounts/list', [
                'user'      => $user,
                'accounts'  => $accounts,
                'csrfToken' => $csrfToken,
                'error'     => is_string($flashError) ? $flashError : null,
            ]),
        );
    }

    // ── POST /accounts ────────────────────────────────────────────────────────

    private function handleListPost(ServerRequestInterface $request): ResponseInterface
    {
        /** @var User $user */
        $user = $request->getAttribute(User::class);

        /** @var CsrfGuardInterface $guard */
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        /** @var array<string, string> $body */
        $body  = (array) ($request->getParsedBody() ?? []);
        $token = (string) ($body['csrf_token'] ?? '');

        if (!$guard->validateToken($token)) {
            return new HtmlResponse('Ungültige Anfrage.', 400);
        }

        $name = trim((string) ($body['name'] ?? ''));
        $slug = trim((string) ($body['slug'] ?? ''));

        if ($name === '' || $slug === '') {
            return new RedirectResponse('/accounts?error=' . rawurlencode('Name und Slug sind erforderlich.'));
        }

        if (!preg_match('/^[a-z0-9\-]{2,64}$/', $slug)) {
            return new RedirectResponse('/accounts?error=' . rawurlencode('Slug: nur Kleinbuchstaben, Ziffern und Bindestriche (2–64 Zeichen).'));
        }

        try {
            $this->accounts->create(
                name: $name,
                slug: $slug,
                ownerUserId: $user->id,
                createdAt: new \DateTimeImmutable()->format('Y-m-d H:i:s'),
            );
        } catch (\Throwable $e) {
            return new RedirectResponse('/accounts?error=' . rawurlencode($e->getMessage()));
        }

        return new RedirectResponse('/accounts');
    }

    // ── GET /accounts/{id} ────────────────────────────────────────────────────

    private function handleEditGet(ServerRequestInterface $request, int $accountId): ResponseInterface
    {
        /** @var User $user */
        $user    = $request->getAttribute(User::class);
        $account = $this->accounts->findById($accountId);

        if (!$account instanceof \TowerDNS\Domain\Account\Account) {
            return new HtmlResponse('Account nicht gefunden.', 404);
        }

        try {
            $this->permissions->assertCanManageAccount($accountId, $user);
        } catch (AuthorizationException) {
            return new HtmlResponse('Kein Zugriff.', 403);
        }

        /** @var CsrfGuardInterface $guard */
        $guard     = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $csrfToken = $guard->generateToken();

        $flashError = $request->getQueryParams()['error'] ?? null;

        return new HtmlResponse(
            $this->renderer->render('app::accounts/edit', [
                'user'      => $user,
                'account'   => $account,
                'csrfToken' => $csrfToken,
                'error'     => is_string($flashError) ? $flashError : null,
            ]),
        );
    }

    // ── POST /accounts/{id} ───────────────────────────────────────────────────

    private function handleEditPost(ServerRequestInterface $request, int $accountId): ResponseInterface
    {
        /** @var User $user */
        $user = $request->getAttribute(User::class);

        try {
            $this->permissions->assertCanManageAccount($accountId, $user);
        } catch (AuthorizationException) {
            return new HtmlResponse('Kein Zugriff.', 403);
        }

        /** @var CsrfGuardInterface $guard */
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        /** @var array<string, string> $body */
        $body  = (array) ($request->getParsedBody() ?? []);
        $token = (string) ($body['csrf_token'] ?? '');

        if (!$guard->validateToken($token)) {
            return new HtmlResponse('Ungültige Anfrage.', 400);
        }

        $action = (string) ($body['action'] ?? 'rename');

        if ($action === 'deactivate') {
            $this->accounts->deactivate($accountId);
            return new RedirectResponse('/accounts');
        }

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            return new RedirectResponse('/accounts/' . $accountId . '?error=' . rawurlencode('Name darf nicht leer sein.'));
        }

        $this->accounts->updateName($accountId, $name);
        return new RedirectResponse('/accounts/' . $accountId);
    }

    // ── GET /accounts/{id}/members ────────────────────────────────────────────

    private function handleMembersGet(ServerRequestInterface $request): ResponseInterface
    {
        /** @var User $user */
        $user      = $request->getAttribute(User::class);
        $accountId = (int) $request->getAttribute('id', 0);
        $account   = $this->accounts->findById($accountId);

        if (!$account instanceof \TowerDNS\Domain\Account\Account) {
            return new HtmlResponse('Account nicht gefunden.', 404);
        }

        try {
            $this->permissions->assertCanManageMembers($accountId, $user);
        } catch (AuthorizationException) {
            return new HtmlResponse('Kein Zugriff.', 403);
        }

        /** @var CsrfGuardInterface $guard */
        $guard     = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $csrfToken = $guard->generateToken();

        $members    = $this->accounts->findMemberships($accountId);
        $flashError = $request->getQueryParams()['error'] ?? null;

        return new HtmlResponse(
            $this->renderer->render('app::accounts/members', [
                'user'      => $user,
                'account'   => $account,
                'members'   => $members,
                'roles'     => TeamRole::cases(),
                'csrfToken' => $csrfToken,
                'error'     => is_string($flashError) ? $flashError : null,
            ]),
        );
    }

    // ── POST /accounts/{id}/members ───────────────────────────────────────────

    private function handleMembersPost(ServerRequestInterface $request): ResponseInterface
    {
        /** @var User $actor */
        $actor    = $request->getAttribute('actor_user') ?? $request->getAttribute(User::class);
        $accountId = (int) $request->getAttribute('id', 0);

        if (!$actor instanceof User) {
            return new HtmlResponse('Kein Zugriff.', 403);
        }

        try {
            $this->permissions->assertCanManageMembers($accountId, $actor);
        } catch (AuthorizationException) {
            return new HtmlResponse('Kein Zugriff.', 403);
        }

        /** @var CsrfGuardInterface $guard */
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        /** @var array<string, string> $body */
        $body  = (array) ($request->getParsedBody() ?? []);
        $token = (string) ($body['csrf_token'] ?? '');

        if (!$guard->validateToken($token)) {
            return new HtmlResponse('Ungültige Anfrage.', 400);
        }

        $action       = (string) ($body['action'] ?? '');
        $targetUserId = trim((string) ($body['user_id'] ?? ''));
        $base         = '/accounts/' . $accountId . '/members';

        if ($action === 'remove') {
            if ($targetUserId === '') {
                return new RedirectResponse($base . '?error=' . rawurlencode('Benutzer-ID fehlt.'));
            }
            try {
                $this->memberships->revoke($actor, $accountId, $targetUserId);
                $this->audit->recordMemberRemoved($request, $actor->id, $accountId, $targetUserId);
            } catch (\Throwable $e) {
                return new RedirectResponse($base . '?error=' . rawurlencode($e->getMessage()));
            }
            return new RedirectResponse($base);
        }

        if ($action === 'invite') {
            $roleVal = trim((string) ($body['role'] ?? ''));
            $role    = TeamRole::tryFrom($roleVal);

            if ($targetUserId === '' || $role === null) {
                return new RedirectResponse($base . '?error=' . rawurlencode('Benutzer-ID und Rolle sind erforderlich.'));
            }

            try {
                $this->memberships->invite($actor, $accountId, $targetUserId, $role);
                $this->audit->recordMemberInvited($request, $actor->id, $accountId, $targetUserId, $role->value);
            } catch (\Throwable $e) {
                return new RedirectResponse($base . '?error=' . rawurlencode($e->getMessage()));
            }

            return new RedirectResponse($base);
        }

        return new RedirectResponse($base . '?error=' . rawurlencode('Unbekannte Aktion.'));
    }

    private function handleOwnershipPost(ServerRequestInterface $request): ResponseInterface
    {
        /** @var User $actor */
        $actor = $request->getAttribute('actor_user') ?? $request->getAttribute(User::class);
        $accountId = (int) $request->getAttribute('id', 0);
        if (!$actor instanceof User) {
            return new RedirectResponse('/accounts?error=' . rawurlencode('Invalid request.'));
        }
        /** @var CsrfGuardInterface $guard */
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        /** @var array<string, string> $body */
        $body = (array) ($request->getParsedBody() ?? []);
        $base = '/accounts/' . $accountId . '/members';
        if (!$guard->validateToken((string) ($body['csrf_token'] ?? ''))) {
            return new RedirectResponse($base . '?error=' . rawurlencode('Invalid request.'));
        }
        $target = trim((string) ($body['user_id'] ?? ''));
        try {
            $this->ownership->transfer($actor, $accountId, $target);
            $this->audit->recordAccountOwnershipTransferred($request, $actor->id, $accountId, $target);
        } catch (\Throwable $e) {
            return new RedirectResponse($base . '?error=' . rawurlencode($e->getMessage()));
        }
        return new RedirectResponse($base);
    }
}

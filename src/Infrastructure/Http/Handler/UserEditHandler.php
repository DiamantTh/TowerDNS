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
use TowerDNS\Application\Repository\RoleRepositoryInterface;
use TowerDNS\Application\Repository\UserRepositoryInterface;
use TowerDNS\Application\Services\AuthorizationService;
use TowerDNS\Domain\Auth\Permission;
use TowerDNS\Domain\Auth\User;

/**
 * GET  /users/{id} — Benutzerdetails + Rollenzuweisung-Formular.
 * POST /users/{id} — syncRoles() für den Benutzer ausführen.
 */
final readonly class UserEditHandler implements RequestHandlerInterface
{
    public function __construct(
        private TemplateRendererInterface $renderer,
        private UserRepositoryInterface   $users,
        private RoleRepositoryInterface   $roles,
        private AuthorizationService      $authz,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var User $currentUser */
        $currentUser = $request->getAttribute(User::class);

        $targetId = (string) $request->getAttribute('id', '');

        /** @var CsrfGuardInterface $guard */
        $guard     = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $csrfToken = $guard->generateToken();

        try {
            $this->authz->assert($currentUser, Permission::USER_MANAGE);
        } catch (AuthorizationException $e) {
            return new HtmlResponse(
                $this->renderer->render('app::iam/user_edit', [
                    'currentUser' => $currentUser,
                    'target'      => null,
                    'allRoles'    => [],
                    'csrfToken'   => $csrfToken,
                    'error'       => $e->getMessage(),
                    'success'     => null,
                ]),
                403,
            );
        }

        $target = $this->users->findById($targetId);
        if (!$target instanceof User) {
            return new HtmlResponse(
                $this->renderer->render('app::iam/user_edit', [
                    'currentUser' => $currentUser,
                    'target'      => null,
                    'allRoles'    => [],
                    'csrfToken'   => $csrfToken,
                    'error'       => 'Benutzer nicht gefunden.',
                    'success'     => null,
                ]),
                404,
            );
        }

        $allRoles = $this->roles->findAll();

        if ($request->getMethod() === 'GET') {
            $flashError   = $request->getQueryParams()['error']   ?? null;
            $flashSuccess = $request->getQueryParams()['success'] ?? null;

            return new HtmlResponse(
                $this->renderer->render('app::iam/user_edit', [
                    'currentUser' => $currentUser,
                    'target'      => $target,
                    'allRoles'    => $allRoles,
                    'csrfToken'   => $csrfToken,
                    'error'       => $flashError,
                    'success'     => $flashSuccess,
                ]),
            );
        }

        // POST — sync roles / update display name
        /** @var array<string, mixed> $body */
        $body  = (array) ($request->getParsedBody() ?? []);
        $raw   = $body['csrf_token'] ?? '';
        $token = is_array($raw) ? (string) ($raw[0] ?? '') : (string) $raw;

        if (!$guard->validateToken($token)) {
            return new RedirectResponse('/users/' . rawurlencode($targetId) . '?error=' . rawurlencode('Ungültige Anfrage.'));
        }

        $action = (string) ($body['action'] ?? 'roles');

        // ── Anzeigename ────────────────────────────────────────────────────
        if ($action === 'display_name') {
            $displayName = trim((string) ($body['display_name'] ?? ''));
            try {
                $this->users->updateDisplayName($targetId, $displayName);
            } catch (\Throwable $e) {
                return new RedirectResponse('/users/' . rawurlencode($targetId) . '?error=' . rawurlencode('Fehler beim Speichern: ' . $e->getMessage()));
            }
            return new RedirectResponse('/users/' . rawurlencode($targetId) . '?success=' . rawurlencode('Anzeigename aktualisiert.'));
        }

        /** @var list<string> $selectedRoles */
        $selectedRoles = [];
        if (isset($body['roles']) && is_array($body['roles'])) {
            foreach ($body['roles'] as $r) {
                if (is_string($r) && $r !== '') {
                    $selectedRoles[] = $r;
                }
            }
        }

        try {
            $this->users->syncRoles($targetId, $selectedRoles);
        } catch (\Throwable $e) {
            return new HtmlResponse(
                $this->renderer->render('app::iam/user_edit', [
                    'currentUser' => $currentUser,
                    'target'      => $target,
                    'allRoles'    => $allRoles,
                    'csrfToken'   => $guard->generateToken(),
                    'error'       => 'Fehler beim Speichern der Rollen: ' . $e->getMessage(),
                    'success'     => null,
                ]),
                500,
            );
        }

        // Reload user so the template reflects the updated roles.
        $updated = $this->users->findById($targetId) ?? $target;

        return new HtmlResponse(
            $this->renderer->render('app::iam/user_edit', [
                'currentUser' => $currentUser,
                'target'      => $updated,
                'allRoles'    => $allRoles,
                'csrfToken'   => $guard->generateToken(),
                'error'       => null,
                'success'     => 'Rollen wurden gespeichert.',
            ]),
        );
    }
}

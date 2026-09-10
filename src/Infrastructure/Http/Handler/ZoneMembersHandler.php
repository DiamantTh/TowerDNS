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
use TowerDNS\Application\Repository\UserRepositoryInterface;
use TowerDNS\Application\Repository\ZoneMembershipRepositoryInterface;
use TowerDNS\Application\Services\AuditLogService;
use TowerDNS\Application\Services\PermissionService;
use TowerDNS\Domain\Account\TeamRole;
use TowerDNS\Domain\Auth\User;

/**
 * Zone-level membership management.
 *
 * Routes handled (all behind RequireAuthMiddleware):
 *   GET  /accounts/{id}/providers/{pid}/zones/{zone}/members → list zone members
 *   POST /accounts/{id}/providers/{pid}/zones/{zone}/members → grant or revoke
 *
 * Zone memberships grant zone-specific access without account-wide rights.
 * Only users with account-level OWNER or ADMIN role may manage zone members.
 */
final readonly class ZoneMembersHandler implements RequestHandlerInterface
{
    public function __construct(
        private TemplateRendererInterface          $renderer,
        private ZoneMembershipRepositoryInterface  $zoneMemberships,
        private UserRepositoryInterface            $users,
        private PermissionService                  $permissions,
        private AuditLogService                    $audit,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $request->getMethod() === 'POST'
            ? $this->handlePost($request)
            : $this->handleGet($request);
    }

    // ── GET ───────────────────────────────────────────────────────────────────

    private function handleGet(ServerRequestInterface $request): ResponseInterface
    {
        /** @var User $user */
        $user      = $request->getAttribute(User::class);
        $accountId = (int) $request->getAttribute('id', 0);
        $zoneId    = (string) $request->getAttribute('zone', '');

        try {
            $this->permissions->assertCanManageMembers($accountId, $user);
        } catch (AuthorizationException) {
            return new HtmlResponse('Kein Zugriff.', 403);
        }

        /** @var CsrfGuardInterface $guard */
        $guard     = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $csrfToken = $guard->generateToken();

        $members    = $this->zoneMemberships->findByZoneId($zoneId);
        $allUsers   = $this->users->findAll();
        $flashError = $request->getQueryParams()['error'] ?? null;

        return new HtmlResponse(
            $this->renderer->render('app::zones/members', [
                'user'      => $user,
                'accountId' => $accountId,
                'zoneId'    => $zoneId,
                'members'   => $members,
                'allUsers'  => $allUsers,
                'roles'     => TeamRole::cases(),
                'csrfToken' => $csrfToken,
                'error'     => is_string($flashError) ? $flashError : null,
            ]),
        );
    }

    // ── POST ──────────────────────────────────────────────────────────────────

    private function handlePost(ServerRequestInterface $request): ResponseInterface
    {
        /** @var User $user */
        $user      = $request->getAttribute(User::class);
        $accountId = (int) $request->getAttribute('id', 0);
        $zoneId    = (string) $request->getAttribute('zone', '');

        try {
            $this->permissions->assertCanManageMembers($accountId, $user);
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
        $base         = '/accounts/' . $accountId . '/providers/' . rawurlencode((string) $request->getAttribute('pid', ''))
            . '/zones/' . rawurlencode($zoneId) . '/members';

        if ($action === 'revoke') {
            if ($targetUserId === '') {
                return new RedirectResponse($base . '?error=' . rawurlencode('Benutzer-ID fehlt.'));
            }

            $this->zoneMemberships->revoke($zoneId, $targetUserId);
            $this->audit->recordZoneMemberRevoked($request, $user->id, $accountId, $zoneId, $targetUserId);

            return new RedirectResponse($base);
        }

        if ($action === 'grant') {
            $roleVal = trim((string) ($body['role'] ?? ''));
            $role    = TeamRole::tryFrom($roleVal);

            if ($targetUserId === '' || $role === null) {
                return new RedirectResponse($base . '?error=' . rawurlencode('Benutzer-ID und Rolle sind erforderlich.'));
            }

            try {
                $this->zoneMemberships->grant(
                    zoneId: $zoneId,
                    userId: $targetUserId,
                    role: $role,
                    createdAt: new \DateTimeImmutable()->format('Y-m-d H:i:s'),
                    accountId: $accountId,
                    grantedBy: $user->id,
                );
                $this->audit->recordZoneMemberGranted($request, $user->id, $accountId, $zoneId, $targetUserId, $role->value);
            } catch (\Throwable $e) {
                return new RedirectResponse($base . '?error=' . rawurlencode($e->getMessage()));
            }

            return new RedirectResponse($base);
        }

        return new RedirectResponse($base . '?error=' . rawurlencode('Unbekannte Aktion.'));
    }
}

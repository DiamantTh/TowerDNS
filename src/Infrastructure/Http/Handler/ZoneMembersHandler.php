<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Laminas\I18n\Translator\TranslatorInterface;
use Mezzio\Csrf\CsrfGuardInterface;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Template\TemplateRendererInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Application\Exception\AuthorizationException;
use TowerDNS\Application\Repository\ManagedZoneRepositoryInterface;
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
        private ManagedZoneRepositoryInterface      $managedZones,
        private UserRepositoryInterface            $users,
        private PermissionService                  $permissions,
        private AuditLogService                    $audit,
        private TranslatorInterface                $translator,
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
        $user          = $request->getAttribute(User::class);
        $accountId     = (int) $request->getAttribute('id', 0);
        $managedZoneId = (int) $request->getAttribute('zone', 0);

        try {
            $this->permissions->assertCanManageMembers($accountId, $user);
        } catch (AuthorizationException) {
            return new HtmlResponse($this->translator->translate('http.error.forbidden'), 403);
        }
        if ($this->managedZones->findByIdForAccount($managedZoneId, $accountId) === null) {
            return new HtmlResponse($this->translator->translate('http.error.not-found'), 404);
        }

        /** @var CsrfGuardInterface $guard */
        $guard     = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $csrfToken = $guard->generateToken();

        $members    = $this->zoneMemberships->findByManagedZoneId($managedZoneId);
        $allUsers   = $this->users->findAll();
        $flashError = $request->getQueryParams()['error'] ?? null;

        return new HtmlResponse(
            $this->renderer->render('app::zones/members', [
                'user'      => $user,
                'accountId' => $accountId,
                'zoneId'    => (string) $managedZoneId,
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
        $user          = $request->getAttribute(User::class);
        $accountId     = (int) $request->getAttribute('id', 0);
        $managedZoneId = (int) $request->getAttribute('zone', 0);

        try {
            $this->permissions->assertCanManageMembers($accountId, $user);
        } catch (AuthorizationException) {
            return new HtmlResponse($this->translator->translate('http.error.forbidden'), 403);
        }
        if ($this->managedZones->findByIdForAccount($managedZoneId, $accountId) === null) {
            return new HtmlResponse($this->translator->translate('http.error.not-found'), 404);
        }

        /** @var CsrfGuardInterface $guard */
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        /** @var array<string, string> $body */
        $body  = (array) ($request->getParsedBody() ?? []);
        $token = (string) ($body['csrf_token'] ?? '');

        if (!$guard->validateToken($token)) {
            return new HtmlResponse($this->translator->translate('http.error.invalid-request'), 400);
        }

        $action       = (string) ($body['action'] ?? '');
        $targetUserId = trim((string) ($body['user_id'] ?? ''));
        $base         = '/accounts/' . $accountId . '/providers/' . rawurlencode((string) $request->getAttribute('pid', ''))
            . '/zones/' . rawurlencode((string) $managedZoneId) . '/members';

        if ($action === 'revoke') {
            if ($targetUserId === '') {
                return new RedirectResponse($base . '?error=' . rawurlencode(
                    $this->translator->translate('zone-members.error.user-required'),
                ));
            }

            $this->zoneMemberships->revoke($managedZoneId, $targetUserId);
            $this->audit->recordZoneMemberRevoked($request, $user->id, $accountId, $managedZoneId, $targetUserId);

            return new RedirectResponse($base);
        }

        if ($action === 'grant') {
            $roleVal = trim((string) ($body['role'] ?? ''));
            $role    = TeamRole::tryFrom($roleVal);

            if ($targetUserId === '' || $role === null) {
                return new RedirectResponse($base . '?error=' . rawurlencode(
                    $this->translator->translate('zone-members.error.user-and-role-required'),
                ));
            }

            try {
                $this->zoneMemberships->grant(
                    managedZoneId: $managedZoneId,
                    userId: $targetUserId,
                    role: $role,
                    createdAt: new \DateTimeImmutable()->format('Y-m-d H:i:s'),
                    grantedBy: $user->id,
                );
                $this->audit->recordZoneMemberGranted($request, $user->id, $accountId, $managedZoneId, $targetUserId, $role->value);
            } catch (\Throwable) {
                return new RedirectResponse($base . '?error=' . rawurlencode(
                    $this->translator->translate('zone-members.error.grant-failed'),
                ));
            }

            return new RedirectResponse($base);
        }

        return new RedirectResponse($base . '?error=' . rawurlencode(
            $this->translator->translate('zone-members.error.unknown-action'),
        ));
    }
}

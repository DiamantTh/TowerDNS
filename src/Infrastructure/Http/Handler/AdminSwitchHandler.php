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
use TowerDNS\Application\Repository\AdminImpersonationSessionRepositoryInterface;
use TowerDNS\Application\Services\AuditLogService;
use TowerDNS\Application\Services\PermissionService;
use TowerDNS\Domain\Auth\User;

/**
 * Admin impersonation (Admin-Switch).
 *
 * Routes handled (all behind RequireAuthMiddleware):
 *   GET  /admin/switch        → form (or active-session info)
 *   POST /admin/switch/start  → start a new impersonation session
 *   POST /admin/switch/end    → end the current session
 *
 * The handler stores the active session ID in the PHP session so that
 * downstream middlewares / handlers can resolve the effective identity.
 *
 * Sessions expire after {@see self::SESSION_TTL_SECONDS} seconds (default 15 min).
 * A reason is mandatory.
 */
final readonly class AdminSwitchHandler implements RequestHandlerInterface
{
    private const int SESSION_TTL_SECONDS = 900; // 15 minutes
    private const string SESSION_KEY      = 'admin_switch_session_id';

    public function __construct(
        private TemplateRendererInterface                    $renderer,
        private AdminImpersonationSessionRepositoryInterface $sessions,
        private PermissionService                            $permissions,
        private AuditLogService                              $audit,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $path   = $request->getUri()->getPath();
        $method = $request->getMethod();

        if ($method === 'POST' && str_ends_with($path, '/start')) {
            return $this->handleStart($request);
        }

        if ($method === 'POST' && str_ends_with($path, '/end')) {
            return $this->handleEnd($request);
        }

        return $this->handleGetForm($request);
    }

    // ── GET /admin/switch ────────────────────────────────────────────────────

    private function handleGetForm(ServerRequestInterface $request): ResponseInterface
    {
        /** @var User $user */
        $user = $request->getAttribute(User::class);

        $this->permissions->assertCanImpersonate($user);

        /** @var CsrfGuardInterface $guard */
        $guard     = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $csrfToken = $guard->generateToken();

        // Check for an active session
        $activeSession = $this->sessions->findActiveForActor($user->id);
        $flashError    = $request->getQueryParams()['error'] ?? null;

        return new HtmlResponse(
            $this->renderer->render('app::admin/switch', [
                'user'          => $user,
                'activeSession' => $activeSession,
                'csrfToken'     => $csrfToken,
                'error'         => is_string($flashError) ? $flashError : null,
            ]),
        );
    }

    // ── POST /admin/switch/start ─────────────────────────────────────────────

    private function handleStart(ServerRequestInterface $request): ResponseInterface
    {
        /** @var User $user */
        $user = $request->getAttribute(User::class);

        $this->permissions->assertCanImpersonate($user);

        /** @var CsrfGuardInterface $guard */
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        /** @var array<string, string> $body */
        $body  = (array) ($request->getParsedBody() ?? []);
        $token = (string) ($body['csrf_token'] ?? '');

        if (!$guard->validateToken($token)) {
            return new HtmlResponse('Ungültige Anfrage.', 400);
        }

        $reason             = trim((string) ($body['reason'] ?? ''));
        $effectiveUserId    = trim((string) ($body['effective_user_id'] ?? '')) ?: null;
        $effectiveAccountId = isset($body['effective_account_id']) && $body['effective_account_id'] !== ''
            ? (int) $body['effective_account_id']
            : null;

        if ($reason === '') {
            return new RedirectResponse('/admin/switch?error=' . rawurlencode('Ein Begründungstext ist Pflichtfeld.'));
        }

        $now       = new \DateTimeImmutable();
        $expiresAt = $now->modify('+' . self::SESSION_TTL_SECONDS . ' seconds');
        $sessionId = bin2hex(random_bytes(16));

        try {
            $this->sessions->create(
                id: $sessionId,
                actorUserId: $user->id,
                effectiveUserId: $effectiveUserId,
                effectiveAccountId: $effectiveAccountId,
                reason: $reason,
                createdAt: $now->format('Y-m-d H:i:s'),
                expiresAt: $expiresAt->format('Y-m-d H:i:s'),
            );

            $this->audit->recordAdminSwitchStart(
                $request,
                $user->id,
                $sessionId,
                $effectiveUserId,
                $effectiveAccountId,
                $reason,
            );
        } catch (\Throwable $e) {
            return new RedirectResponse('/admin/switch?error=' . rawurlencode($e->getMessage()));
        }

        // Store session ID in PHP session
        /** @var \Mezzio\Session\SessionInterface $session */
        $session = $request->getAttribute(\Mezzio\Session\SessionInterface::class);
        $session->set(self::SESSION_KEY, $sessionId);

        return new RedirectResponse('/admin/switch');
    }

    // ── POST /admin/switch/end ────────────────────────────────────────────────

    private function handleEnd(ServerRequestInterface $request): ResponseInterface
    {
        /** @var User $user */
        $user = $request->getAttribute(User::class);

        $this->permissions->assertCanImpersonate($user);

        /** @var CsrfGuardInterface $guard */
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        /** @var array<string, string> $body */
        $body  = (array) ($request->getParsedBody() ?? []);
        $token = (string) ($body['csrf_token'] ?? '');

        if (!$guard->validateToken($token)) {
            return new HtmlResponse('Ungültige Anfrage.', 400);
        }

        $activeSession = $this->sessions->findActiveForActor($user->id);

        if (!$activeSession instanceof \TowerDNS\Domain\Account\AdminImpersonationSession) {
            return new RedirectResponse('/admin/switch?error=' . rawurlencode('Keine aktive Sitzung gefunden.'));
        }

        $now = new \DateTimeImmutable();
        $this->sessions->end($activeSession->id, $now->format('Y-m-d H:i:s'));
        $this->audit->recordAdminSwitchEnd($request, $user->id, $activeSession->id);

        // Remove from PHP session
        /** @var \Mezzio\Session\SessionInterface $session */
        $session = $request->getAttribute(\Mezzio\Session\SessionInterface::class);
        $session->unset(self::SESSION_KEY);

        return new RedirectResponse('/admin/switch');
    }
}

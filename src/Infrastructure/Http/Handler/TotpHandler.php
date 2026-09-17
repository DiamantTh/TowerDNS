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
use Mezzio\Session\SessionInterface;
use Mezzio\Template\TemplateRendererInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\SimpleCache\CacheInterface;
use TowerDNS\Application\Repository\UserRepositoryInterface;
use TowerDNS\Application\Services\AuditLogService;
use TowerDNS\Application\Services\TotpSecretService;
use TowerDNS\Infrastructure\Http\SessionSecurity;
use TowerDNS\Infrastructure\RateLimit\RateLimiter;
use TowerDNS\Infrastructure\RateLimit\RateLimitExceededException;

/**
 * GET  /login/totp — show TOTP input form.
 * POST /login/totp — verify TOTP code and complete login.
 *
 * Requires session[mfa_pending] to be set by {@see LoginHandler}.
 * If the session does not contain that key the user is redirected back
 * to /login.
 */
final readonly class TotpHandler implements RequestHandlerInterface
{
    private const int RATE_LIMIT       = 10;
    private const int RATE_WINDOW_SECS = 300; // 5 minutes

    public function __construct(
        private TemplateRendererInterface $renderer,
        private UserRepositoryInterface   $users,
        private TotpSecretService         $secrets,
        private AuditLogService           $audit,
        private TranslatorInterface       $translator,
        private SessionSecurity           $sessionSecurity,
        private CacheInterface            $cache,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $session = $request->getAttribute(SessionInterface::class);

        if (!$session instanceof SessionInterface) {
            return new RedirectResponse('/login');
        }

        $pendingUserId = $this->sessionSecurity->pendingMfaUserId($session);
        if ($pendingUserId === null) {
            return new RedirectResponse('/login');
        }

        /** @var CsrfGuardInterface $guard */
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);

        if ($request->getMethod() === 'GET') {
            return new HtmlResponse(
                $this->renderer->render('app::mfa_totp', [
                    'error'     => null,
                    'csrfToken' => $guard->generateToken(),
                ])
            );
        }

        // POST — verify TOTP code
        /** @var array<string, string> $body */
        $body  = (array) ($request->getParsedBody() ?? []);
        $token = (string) ($body['csrf_token'] ?? '');

        if (!$guard->validateToken($token)) {
            return new HtmlResponse(
                $this->renderer->render('app::mfa_totp', [
                    'error'     => $this->translator->translate('auth.error.invalid-request'),
                    'csrfToken' => $guard->generateToken(),
                ]),
                400
            );
        }

        $code   = trim((string) ($body['code'] ?? ''));
        $userId = $pendingUserId;

        if ($code === '') {
            return $this->renderError($this->translator->translate('totp.error.code-required'), $guard);
        }

        $limiter = new RateLimiter(
            $this->cache,
            'totp_' . hash('sha256', $userId),
            self::RATE_LIMIT,
            self::RATE_WINDOW_SECS,
            'TOTP',
        );
        try {
            $limiter->hit();
        } catch (RateLimitExceededException) {
            return new HtmlResponse(
                $this->renderer->render('app::mfa_totp', [
                    'error'     => $this->translator->translate('auth.error.rate-limited'),
                    'csrfToken' => $guard->generateToken(),
                ]),
                429,
            );
        }

        if (!$this->secrets->verify($userId, $code)) {
            return $this->renderError($this->translator->translate('totp.error.code-invalid'), $guard);
        }

        // Code correct — complete login.
        $this->sessionSecurity->completeLogin($session, $userId);
        $this->users->updateLastLoginAt($userId);
        $this->audit->recordLogin($request, $userId);

        return new RedirectResponse('/');
    }

    private function renderError(string $error, CsrfGuardInterface $guard): ResponseInterface
    {
        return new HtmlResponse(
            $this->renderer->render('app::mfa_totp', [
                'error'     => $error,
                'csrfToken' => $guard->generateToken(),
            ]),
            401
        );
    }
}

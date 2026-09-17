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
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\SimpleCache\CacheInterface;
use TowerDNS\Application\Exception\PasswordResetException;
use TowerDNS\Application\Repository\PasswordResetTokenRepositoryInterface;
use TowerDNS\Application\Services\AuditLogService;
use TowerDNS\Application\Services\PasswordResetService;
use TowerDNS\Infrastructure\Http\ClientIpResolver;
use TowerDNS\Infrastructure\RateLimit\RateLimiter;
use TowerDNS\Infrastructure\RateLimit\RateLimitExceededException;

/**
 * Password-reset completion handler.
 *
 * GET  /password/reset?token=<raw>  — show new-password form
 * POST /password/reset              — validate token + update password
 */
final readonly class ResetPasswordHandler implements RequestHandlerInterface
{
    private const int RATE_LIMIT       = 10;
    private const int RATE_WINDOW_SECS = 900; // 15 minutes

    public function __construct(
        private TemplateRendererInterface             $renderer,
        private PasswordResetTokenRepositoryInterface $tokens,
        private PasswordResetService                  $resets,
        private AuditLogService                       $audit,
        private TranslatorInterface                   $translator,
        private ClockInterface                        $clock,
        private CacheInterface                        $cache,
        private ClientIpResolver                       $clientIp,
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
        /** @var CsrfGuardInterface $guard */
        $guard    = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $rawToken = trim((string) ($request->getQueryParams()['token'] ?? ''));

        if ($rawToken === '' || !$this->tokenIsValid($rawToken)) {
            return new HtmlResponse(
                $this->renderer->render('app::reset_password', [
                    'csrfToken' => $guard->generateToken(),
                    'token'     => '',
                    'error'     => $this->translator->translate('auth.error.reset-link-invalid'),
                ]),
                400
            );
        }

        return new HtmlResponse(
            $this->renderer->render('app::reset_password', [
                'csrfToken' => $guard->generateToken(),
                'token'     => $rawToken,
                'error'     => null,
            ])
        );
    }

    // ── POST ──────────────────────────────────────────────────────────────────

    private function handlePost(ServerRequestInterface $request): ResponseInterface
    {
        /** @var CsrfGuardInterface $guard */
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        /** @var array<string, string> $body */
        $body  = (array) ($request->getParsedBody() ?? []);
        $token = (string) ($body['csrf_token'] ?? '');

        if (!$guard->validateToken($token)) {
            return new HtmlResponse(
                $this->renderer->render('app::reset_password', [
                    'csrfToken' => $guard->generateToken(),
                    'token'     => (string) ($body['reset_token'] ?? ''),
                    'error'     => $this->translator->translate('http.error.invalid-request'),
                ]),
                400
            );
        }

        $rawToken = trim((string) ($body['reset_token'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $confirm  = (string) ($body['password_confirm'] ?? '');

        $renderError = (fn(string $msg): HtmlResponse => new HtmlResponse(
            $this->renderer->render('app::reset_password', [
                'csrfToken' => $guard->generateToken(),
                'token'     => $rawToken,
                'error'     => $msg,
            ]),
            422
        ));

        $ip      = (string) ($this->clientIp->resolve($request) ?? '');
        $limiter = new RateLimiter(
            $this->cache,
            'pwreset_' . hash('sha256', $ip),
            self::RATE_LIMIT,
            self::RATE_WINDOW_SECS,
            'PasswordReset',
        );
        try {
            $limiter->hit();
        } catch (RateLimitExceededException) {
            return new HtmlResponse(
                $this->renderer->render('app::reset_password', [
                    'csrfToken' => $guard->generateToken(),
                    'token'     => $rawToken,
                    'error'     => $this->translator->translate('auth.error.rate-limited'),
                ]),
                429,
            );
        }

        if ($password !== $confirm) {
            return $renderError($this->translator->translate('auth.error.passwords-do-not-match'));
        }

        try {
            $userId = $this->resets->consumeEmailLink($rawToken, $password);
        } catch (\InvalidArgumentException) {
            return $renderError($this->translator->translate('auth.error.password-policy'));
        } catch (PasswordResetException) {
            return $renderError($this->translator->translate('auth.error.reset-link-invalid'));
        } catch (\Throwable) {
            return $renderError($this->translator->translate('auth.error.password-reset-failed'));
        }

        $this->audit->recordPasswordReset($request, $userId);

        return new RedirectResponse('/login?reset=1');
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    private function tokenIsValid(string $rawToken): bool
    {
        $record = $this->tokens->findByHash(hash('sha256', $rawToken));
        return $record instanceof \TowerDNS\Domain\Auth\PasswordResetToken && $record->isValidAt($this->clock->now());
    }
}

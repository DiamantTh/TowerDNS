<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Laminas\Translator\TranslatorInterface;
use Mezzio\Csrf\CsrfGuardInterface;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Template\TemplateRendererInterface;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\SimpleCache\CacheInterface;
use TowerDNS\Application\Repository\PasswordResetTokenRepositoryInterface;
use TowerDNS\Application\Repository\UserRepositoryInterface;
use TowerDNS\Application\Services\AuditLogService;
use TowerDNS\Application\Services\MailService;
use TowerDNS\Infrastructure\Http\ClientIpResolver;
use TowerDNS\Infrastructure\RateLimit\RateLimiter;
use TowerDNS\Infrastructure\RateLimit\RateLimitExceededException;

/**
 * Password-forgot flow.
 *
 * GET  /password/forgot  — show request form
 * POST /password/forgot  — send reset link (if address is known)
 *
 * The response is identical regardless of whether the address exists in the
 * database to prevent user-enumeration attacks.
 */
final readonly class ForgotPasswordHandler implements RequestHandlerInterface
{
    private const int TOKEN_TTL_SECONDS = 86400; // 24 hours
    private const int RATE_LIMIT        = 5;
    private const int RATE_WINDOW_SECS  = 900; // 15 minutes

    public function __construct(
        private TemplateRendererInterface           $renderer,
        private UserRepositoryInterface             $users,
        private PasswordResetTokenRepositoryInterface $tokens,
        private MailService                         $mail,
        private string                              $appBaseUrl,
        private TranslatorInterface                 $translator,
        private CacheInterface                      $cache,
        private ClockInterface                       $clock,
        private AuditLogService                      $audit,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var CsrfGuardInterface $guard */
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);

        if ($request->getMethod() === 'GET') {
            return new HtmlResponse(
                $this->renderer->render('app::forgot_password', [
                    'csrfToken' => $guard->generateToken(),
                    'sent'      => false,
                ])
            );
        }

        // POST
        $body  = \TowerDNS\Infrastructure\Http\FormInput::fromParsedBody($request->getParsedBody());
        $token = ($body['csrf_token'] ?? '');

        if (!$guard->validateToken($token)) {
            return new HtmlResponse(
                $this->renderer->render('app::forgot_password', [
                    'csrfToken' => $guard->generateToken(),
                    'sent'      => false,
                    'error'     => $this->translator->translate('http.error.invalid-request'),
                ]),
                400
            );
        }

        $email = strtolower(trim(($body['email'] ?? '')));

        $ip      = (string) ($request->getAttribute(ClientIpResolver::ATTRIBUTE) ?? '');
        $limiter = new RateLimiter(
            $this->cache,
            'forgot_' . hash('sha256', $ip),
            self::RATE_LIMIT,
            self::RATE_WINDOW_SECS,
            'ForgotPassword',
        );
        try {
            $limiter->hit();
        } catch (RateLimitExceededException) {
            // Same generic "sent" redirect as success — do not reveal that the
            // request was throttled, which would itself leak information.
            return new RedirectResponse('/password/forgot?sent=1');
        }

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            $user = $this->users->findByEmail($email);

            if ($user instanceof \TowerDNS\Domain\Auth\User) {
                $rawToken  = bin2hex(random_bytes(32)); // 64-char hex string
                $tokenHash = hash('sha256', $rawToken);
                $expiresAt = $this->clock->now()
                    ->modify('+' . self::TOKEN_TTL_SECONDS . ' seconds')
                    ->format('Y-m-d H:i:s');

                $this->tokens->create($user->id, $tokenHash, $expiresAt);
                $this->audit->recordPasswordResetTicketCreated($request, $user->id, $expiresAt);

                $resetLink = rtrim($this->appBaseUrl, '/') . '/password/reset?token=' . rawurlencode($rawToken);
                $htmlLink  = '<a href="' . htmlspecialchars($resetLink, ENT_QUOTES) . '">' . htmlspecialchars($resetLink, ENT_QUOTES) . '</a>';
                $this->mail->send(
                    $user->email,
                    $this->translator->translate('auth.reset-email.subject'),
                    strtr($this->translator->translate('auth.reset-email.html'), ['{link}' => $htmlLink]),
                    strtr($this->translator->translate('auth.reset-email.text'), ['{link}' => $resetLink]),
                );
            }
            // No else branch — identical response regardless of whether user exists.
        }

        // Always redirect to the same "sent" page to prevent enumeration.
        return new RedirectResponse('/password/forgot?sent=1');
    }
}

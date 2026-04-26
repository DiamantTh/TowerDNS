<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Mezzio\Csrf\CsrfGuardInterface;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Session\SessionInterface;
use Mezzio\Template\TemplateRendererInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\SimpleCache\CacheInterface;
use TowerDNS\Application\Repository\UserRepositoryInterface;
use TowerDNS\Application\Repository\WebAuthnCredentialRepositoryInterface;
use TowerDNS\Infrastructure\RateLimit\RateLimiter;
use TowerDNS\Infrastructure\RateLimit\RateLimitExceededException;

/**
 * Handles GET /login (show form) and POST /login (authenticate).
 *
 * MFA flow:
 *   POST /login  — verify email+password
 *     → if TOTP enabled: set session[mfa_pending] and redirect to /login/totp
 *     → else: complete login immediately
 */
final readonly class LoginHandler implements RequestHandlerInterface
{
    private const int RATE_LIMIT       = 10;
    private const int RATE_WINDOW_SECS = 300; // 5 minutes

    public function __construct(
        private TemplateRendererInterface              $renderer,
        private UserRepositoryInterface               $users,
        private CacheInterface                        $cache,
        private WebAuthnCredentialRepositoryInterface $webAuthnCredentials,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $session = $request->getAttribute(SessionInterface::class);

        if ($request->getMethod() === 'GET') {
            /** @var CsrfGuardInterface $guard */
            $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
            return new HtmlResponse(
                $this->renderer->render('app::login', [
                    'error'     => null,
                    'csrfToken' => $guard->generateToken(),
                ])
            );
        }

        // POST — authenticate
        /** @var CsrfGuardInterface $guard */
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);

        // Rate-limit by client IP before doing any DB work.
        $ip      = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');
        $limiter = new RateLimiter(
            $this->cache,
            'login_' . hash('sha256', $ip),
            self::RATE_LIMIT,
            self::RATE_WINDOW_SECS,
            'Login',
        );
        try {
            $limiter->hit();
        } catch (RateLimitExceededException) {
            return new HtmlResponse(
                $this->renderer->render('app::login', [
                    'error'     => 'Zu viele Anmeldeversuche. Bitte warte einige Minuten und versuche es erneut.',
                    'csrfToken' => $guard->generateToken(),
                ]),
                429,
            );
        }

        /** @var array<string, string> $body */
        $body  = (array) ($request->getParsedBody() ?? []);
        $token = (string) ($body['csrf_token'] ?? '');

        if (!$guard->validateToken($token)) {
            return new HtmlResponse(
                $this->renderer->render('app::login', [
                    'error'     => 'Ungültige Anfrage. Bitte versuche es erneut.',
                    'csrfToken' => $guard->generateToken(),
                ]),
                400
            );
        }

        $email = trim((string) ($body['email'] ?? ''));
        $pass  = (string) ($body['password'] ?? '');

        $error = $this->authenticate($email, $pass, $session);

        if ($error !== null) {
            return new HtmlResponse(
                $this->renderer->render('app::login', [
                    'error'     => $error,
                    'csrfToken' => $guard->generateToken(),
                ]),
                401
            );
        }

        // authenticate() sets session or mfa_pending — determine redirect
        if (!$session instanceof SessionInterface) {
            return new RedirectResponse('/login');
        }

        if ($session->has('mfa_pending')) {
            $mfaType = $session->get('mfa_type');
            if ($mfaType === 'webauthn') {
                return new RedirectResponse('/login/webauthn');
            }
            return new RedirectResponse('/login/totp');
        }

        return new RedirectResponse('/');
    }

    private function authenticate(
        string $email,
        string $password,
        mixed  $session,
    ): ?string {
        if ($email === '' || $password === '') {
            return 'E-Mail und Passwort sind erforderlich.';
        }

        // Fetch hash first.  If the address is unknown we still call
        // password_verify with a dummy hash so that every code-path takes a
        // similar amount of time and e-mail enumeration via timing is not
        // possible.
        $hash = $this->users->fetchPasswordHash($email);

        // Pre-computed Argon2id hash used only as a timing dummy.
        // The all-zero hash bytes will never match a real password.
        $hashToVerify = $hash
            ?? '$argon2id$v=19$m=131072,t=4,p=4$Y29waWxvdGR1bW15c2FsdA$ZHVtbXloYXNoZm9yY29waWxvdGNvcnJlY3R0aW1pbmc';

        $valid = password_verify($password, $hashToVerify);

        if ($hash === null || !$valid) {
            return 'Ungültige Anmeldedaten.';
        }

        // Hash matched — now load the full user object (with roles).
        $user = $this->users->findByEmail($email);
        if (!$user instanceof \TowerDNS\Domain\Auth\User) {
            // Account disabled between hash-fetch and user-load (race), or
            // findByEmail's active=1 guard excluded it.
            return 'Ungültige Anmeldedaten.';
        }

        if (!$session instanceof SessionInterface) {
            return 'Session nicht verfügbar.';
        }

        // Check whether TOTP is configured for this user.
        $totpSecret = $this->users->fetchTotpSecret($user->id);

        if ($totpSecret !== null) {
            // TOTP required — store pending state without completing the login.
            $session->regenerate();
            $session->set('mfa_pending', $user->id);
            $session->set('mfa_type', 'totp');
            return null;
        }

        // Check whether WebAuthn credentials are registered.
        $webAuthnKeys = $this->webAuthnCredentials->findByUserId($user->id);
        if ($webAuthnKeys !== []) {
            $session->regenerate();
            $session->set('mfa_pending', $user->id);
            $session->set('mfa_type', 'webauthn');
            return null;
        }

        // No MFA → complete login immediately.
        $session->regenerate();
        $session->set('user_id', $user->id);
        $this->users->updateLastLoginAt($user->id);

        return null;
    }
}

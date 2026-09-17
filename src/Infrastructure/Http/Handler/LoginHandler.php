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
use TowerDNS\Application\Repository\WebAuthnCredentialRepositoryInterface;
use TowerDNS\Application\Services\AuditLogService;
use TowerDNS\Application\Services\TotpSecretService;
use TowerDNS\Application\Validation\LoginInputFilter;
use TowerDNS\Infrastructure\Http\ClientIpResolver;
use TowerDNS\Infrastructure\Http\SessionSecurity;
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
    private const int RATE_LIMIT         = 10;
    private const int RATE_WINDOW_SECS   = 300; // 5 minutes
    private const array ARGON2ID_OPTIONS = [
        'memory_cost' => 131072,
        'time_cost'   => 4,
        'threads'     => 4,
    ];

    public function __construct(
        private TemplateRendererInterface              $renderer,
        private UserRepositoryInterface               $users,
        private CacheInterface                        $cache,
        private WebAuthnCredentialRepositoryInterface $webAuthnCredentials,
        private TotpSecretService                     $totpSecrets,
        private AuditLogService                       $audit,
        private TranslatorInterface                   $translator,
        private SessionSecurity                       $sessionSecurity,
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

        // Rate-limit by client IP before doing any DB work. The real IP
        // (behind any configured trusted proxy) was already resolved by
        // ClientIpMiddleware earlier in the pipeline.
        $ip      = (string) ($request->getAttribute(ClientIpResolver::ATTRIBUTE) ?? '');
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
                    'error'     => $this->translator->translate('auth.error.rate-limited'),
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
                    'error'     => $this->translator->translate('auth.error.invalid-request'),
                    'csrfToken' => $guard->generateToken(),
                ]),
                400
            );
        }

        $email = trim((string) ($body['email'] ?? ''));
        $pass  = (string) ($body['password'] ?? '');

        // Normalize the email address (lowercase) via the LoginInputFilter.
        // If the filter marks the input as invalid, authenticate() will still
        // return 'Ungültige Anmeldedaten.' — no extra information is leaked.
        $loginFilter = new LoginInputFilter();
        $loginFilter->setData(['email' => $email, 'password' => $pass]);
        if ($loginFilter->isValid()) {
            /** @var array{email: string, password: string} $filtered */
            $filtered = $loginFilter->getValues();
            $email    = $filtered['email'];
        }

        $error = $this->authenticate($request, $email, $pass, $session);

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
        ServerRequestInterface $request,
        string $email,
        string $password,
        SessionInterface &$session,
    ): ?string {
        if ($email === '' || $password === '') {
            return $this->translator->translate('auth.error.email-password-required');
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

        $hashInfo = password_get_info($hashToVerify);
        $valid    = password_verify($password, $hashToVerify);

        if ($hash === null || $hashInfo['algo'] === null || !$valid) {
            $this->audit->recordLoginFailed($request, $email);
            return $this->translator->translate('auth.error.invalid-credentials');
        }

        // Hash matched — now load the full user object (with roles).
        $user = $this->users->findByEmail($email);
        if (!$user instanceof \TowerDNS\Domain\Auth\User) {
            // Account disabled between hash-fetch and user-load (race), or
            // findByEmail's active=1 guard excluded it.
            $this->audit->recordLoginFailed($request, $email);
            return $this->translator->translate('auth.error.invalid-credentials');
        }

        // Valid legacy hashes remain supported, but are upgraded to the
        // configured Argon2id parameters after a successful authentication.
        if (password_needs_rehash($hash, PASSWORD_ARGON2ID, self::ARGON2ID_OPTIONS)) {
            $rehash = password_hash($password, PASSWORD_ARGON2ID, self::ARGON2ID_OPTIONS);
            $this->users->updatePasswordHash($user->id, $rehash);
        }

        // Check whether TOTP is configured for this user.
        if ($this->totpSecrets->isEnabled($user->id)) {
            // TOTP required — store pending state without completing the login.
            $session = $this->sessionSecurity->beginMfa($session, $user->id, 'totp');
            return null;
        }

        // Check whether WebAuthn credentials are registered.
        $webAuthnKeys = $this->webAuthnCredentials->findByUserId($user->id);
        if ($webAuthnKeys !== []) {
            $session = $this->sessionSecurity->beginMfa($session, $user->id, 'webauthn');
            return null;
        }

        // No MFA → complete login immediately.
        $session = $this->sessionSecurity->completeLogin($session, $user->id);
        $this->users->updateLastLoginAt($user->id);
        $this->audit->recordLogin($request, $user->id);

        return null;
    }
}

<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

use Laminas\Diactoros\Response\JsonResponse;
use Laminas\I18n\Translator\TranslatorInterface;
use Mezzio\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\SimpleCache\CacheInterface;
use TowerDNS\Application\Repository\UserRepositoryInterface;
use TowerDNS\Application\Repository\WebAuthnCredentialRepositoryInterface;
use TowerDNS\Application\Services\AuditLogService;
use TowerDNS\Application\Services\WebAuthnService;
use TowerDNS\Infrastructure\Http\SessionSecurity;
use TowerDNS\Infrastructure\RateLimit\RateLimiter;
use TowerDNS\Infrastructure\RateLimit\RateLimitExceededException;
use Webauthn\Exception\AuthenticatorResponseVerificationException;

/**
 * POST /login/webauthn/finish
 *
 * Validates the assertion response sent by the browser, completes the
 * session login, and redirects to the dashboard.
 *
 * The request body must be the JSON object produced by
 * navigator.credentials.get() (with ArrayBuffers encoded as base64url).
 */
final readonly class WebAuthnAuthFinishHandler implements RequestHandlerInterface
{
    private const int RATE_LIMIT       = 10;
    private const int RATE_WINDOW_SECS = 300; // 5 minutes

    public function __construct(
        private WebAuthnService                       $webAuthn,
        private WebAuthnCredentialRepositoryInterface $credentialRepo,
        private UserRepositoryInterface               $users,
        private AuditLogService                       $audit,
        private TranslatorInterface                   $translator,
        private SessionSecurity                       $sessionSecurity,
        private CacheInterface                        $cache,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $session = $request->getAttribute(SessionInterface::class);
        assert($session instanceof SessionInterface);

        $userId      = $this->sessionSecurity->pendingMfaUserId($session);
        $optionsJson = $session->get('webauthn_auth_options');

        if ($userId === null || !is_string($optionsJson) || $optionsJson === '') {
            return new JsonResponse(['error' => $this->translator->translate('webauthn.error.authentication-pending')], 400);
        }

        // Consume session state immediately (replay protection).
        $session->unset('webauthn_auth_options');

        $limiter = new RateLimiter(
            $this->cache,
            'webauthn_' . hash('sha256', $userId),
            self::RATE_LIMIT,
            self::RATE_WINDOW_SECS,
            'WebAuthn',
        );
        try {
            $limiter->hit();
        } catch (RateLimitExceededException) {
            return new JsonResponse(['error' => $this->translator->translate('auth.error.rate-limited')], 429);
        }

        $body = (string) $request->getBody();
        if ($body === '') {
            return new JsonResponse(['error' => $this->translator->translate('webauthn.error.invalid-request')], 400);
        }

        // Determine which credential was used.
        /** @var array<string, mixed> $parsed */
        $parsed   = json_decode($body, true) ?? [];
        $rawIdB64 = (string) ($parsed['rawId'] ?? $parsed['id'] ?? '');
        if ($rawIdB64 === '') {
            return new JsonResponse(['error' => $this->translator->translate('webauthn.error.invalid-response')], 422);
        }

        $credentialId = base64_decode(strtr($rawIdB64, '-_', '+/'), true);
        if ($credentialId === false) {
            return new JsonResponse(['error' => $this->translator->translate('webauthn.error.invalid-response')], 422);
        }

        $source = $this->credentialRepo->findByCredentialId($credentialId);
        if (!$source instanceof \Webauthn\PublicKeyCredentialSource) {
            return new JsonResponse(['error' => $this->translator->translate('webauthn.error.authentication-failed')], 422);
        }

        try {
            $requestOptions = $this->webAuthn->deserializeRequestOptions($optionsJson);
            $updatedSource  = $this->webAuthn->parseAndValidateAuthentication($body, $source, $requestOptions, $userId);
        } catch (AuthenticatorResponseVerificationException|\InvalidArgumentException) {
            return new JsonResponse(['error' => $this->translator->translate('webauthn.error.authentication-failed')], 422);
        }

        // Persist updated counter + backup flags.
        $this->credentialRepo->updateAfterAuthentication($credentialId, $updatedSource->counter);

        // Complete login.
        $this->sessionSecurity->completeLogin($session, $userId);
        $this->users->updateLastLoginAt($userId);
        $this->audit->recordLogin($request, $userId);

        return new JsonResponse(['ok' => true, 'redirect' => '/']);
    }
}

<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Translator\TranslatorInterface;
use Mezzio\Csrf\CsrfGuardInterface;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\SimpleCache\CacheInterface;
use TowerDNS\Application\Repository\UserRepositoryInterface;
use TowerDNS\Application\Repository\WebAuthnCredentialRepositoryInterface;
use TowerDNS\Application\Services\AuditLogService;
use TowerDNS\Application\Services\WebAuthnService;
use TowerDNS\Infrastructure\Http\ClientIpResolver;
use TowerDNS\Infrastructure\Http\FormInput;
use TowerDNS\Infrastructure\Http\SessionSecurity;
use TowerDNS\Infrastructure\RateLimit\RateLimiter;
use TowerDNS\Infrastructure\RateLimit\RateLimitExceededException;
use Webauthn\PublicKeyCredentialRequestOptions;

/** Starts username-first, passwordless WebAuthn authentication. */
final readonly class WebAuthnAuthBeginHandler implements RequestHandlerInterface
{
    private const int RATE_LIMIT       = 10;
    private const int RATE_WINDOW_SECS = 300;

    public function __construct(
        private WebAuthnService $webAuthn,
        private WebAuthnCredentialRepositoryInterface $credentials,
        private UserRepositoryInterface $users,
        private SessionSecurity $sessionSecurity,
        private AuditLogService $audit,
        private TranslatorInterface $translator,
        private CacheInterface $cache,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $session = $request->getAttribute(SessionInterface::class);
        if (!$session instanceof SessionInterface) {
            return new JsonResponse(['error' => $this->translator->translate('webauthn.error.authentication-failed')], 401);
        }

        /** @var CsrfGuardInterface $guard */
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $body  = FormInput::fromParsedBody($request->getParsedBody());
        if (!$guard->validateToken($body['csrf_token'] ?? '')) {
            return new JsonResponse(['error' => $this->translator->translate('auth.error.invalid-request')], 400);
        }

        $ip = (string) ($request->getAttribute(ClientIpResolver::ATTRIBUTE) ?? '');
        try {
            new RateLimiter($this->cache, 'webauthn_begin_' . hash('sha256', $ip), self::RATE_LIMIT, self::RATE_WINDOW_SECS, 'WebAuthn')->hit();
        } catch (RateLimitExceededException) {
            return new JsonResponse(['error' => $this->translator->translate('auth.error.rate-limited')], 429);
        }

        $email       = mb_strtolower(trim($body['email'] ?? ''));
        $user        = $email !== '' ? $this->users->findByEmail($email) : null;
        $credentials = $user instanceof \TowerDNS\Domain\Auth\User ? $this->credentials->findByUserId($user->id) : [];
        if (!$user instanceof \TowerDNS\Domain\Auth\User || $credentials === []) {
            $this->audit->recordLoginFailed($request, $email);
            return new JsonResponse(['error' => $this->translator->translate('webauthn.error.authentication-failed')], 401);
        }

        $this->sessionSecurity->clearPasswordVerification($session);
        $this->sessionSecurity->beginMfa($session, $user->id, 'passwordless');
        $ids        = [];
        $transports = [];
        foreach ($credentials as $credential) {
            $id              = $credential['source']->publicKeyCredentialId;
            $ids[]           = $id;
            $transports[$id] = array_values(array_filter($credential['source']->transports, is_string(...)));
        }
        $options = $this->webAuthn->createAuthenticationOptions(
            $ids,
            PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            $transports,
        );
        $serialized = $this->webAuthn->serializeRequestOptions($options);
        $session->set('webauthn_auth_options', $serialized);

        return new JsonResponse(json_decode($serialized, true, flags: JSON_THROW_ON_ERROR));
    }
}

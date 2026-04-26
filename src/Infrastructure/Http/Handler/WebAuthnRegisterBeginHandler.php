<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

use Laminas\Diactoros\Response\JsonResponse;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Application\Repository\WebAuthnCredentialRepositoryInterface;
use TowerDNS\Application\Services\WebAuthnService;
use TowerDNS\Domain\Auth\User;

/**
 * POST /profile/webauthn/register/begin
 *
 * Generates WebAuthn registration options and stores the challenge in the session.
 * Returns JSON suitable for passing to navigator.credentials.create().
 *
 * Request body (form-encoded or JSON):
 *   name       – human-readable label for the new key
 *   csrf_token – CSRF token
 */
final readonly class WebAuthnRegisterBeginHandler implements RequestHandlerInterface
{
    public function __construct(
        private WebAuthnService                       $webAuthn,
        private WebAuthnCredentialRepositoryInterface $credentialRepo,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var \Mezzio\Csrf\CsrfGuardInterface $guard */
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);

        /** @var array<string, string> $body */
        $body  = (array) ($request->getParsedBody() ?? []);
        $token = (string) ($body['csrf_token'] ?? '');

        if (!$guard->validateToken($token)) {
            return new JsonResponse(['error' => 'Ungültige Anfrage.'], 400);
        }

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            return new JsonResponse(['error' => 'Bitte gib einen Namen für den Schlüssel an.'], 422);
        }
        if (mb_strlen($name) > 100) {
            return new JsonResponse(['error' => 'Der Name darf höchstens 100 Zeichen lang sein.'], 422);
        }

        /** @var User $currentUser */
        $currentUser = $request->getAttribute(User::class);

        // Collect existing credential IDs to pass as excludeCredentials.
        $existing   = $this->credentialRepo->findByUserId($currentUser->id);
        $excludeIds = array_column($existing, 'credential_id');

        $options = $this->webAuthn->createRegistrationOptions(
            userId: $currentUser->id,
            userEmail: $currentUser->email,
            displayName: $currentUser->displayName ?? $currentUser->email,
            excludedCredentialIds: $excludeIds,
        );

        $session = $request->getAttribute(SessionInterface::class);
        assert($session instanceof SessionInterface);
        $session->set('webauthn_register_options', $this->webAuthn->serializeCreationOptions($options));
        $session->set('webauthn_register_name', $name);

        return new JsonResponse(
            json_decode($this->webAuthn->serializeCreationOptions($options), true),
            200,
        );
    }
}

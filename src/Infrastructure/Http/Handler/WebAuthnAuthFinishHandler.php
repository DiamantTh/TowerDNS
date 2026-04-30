<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

use Laminas\Diactoros\Response\JsonResponse;
use Mezzio\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Application\Repository\UserRepositoryInterface;
use TowerDNS\Application\Repository\WebAuthnCredentialRepositoryInterface;
use TowerDNS\Application\Services\AuditLogService;
use TowerDNS\Application\Services\WebAuthnService;
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
    public function __construct(
        private WebAuthnService                       $webAuthn,
        private WebAuthnCredentialRepositoryInterface $credentialRepo,
        private UserRepositoryInterface               $users,
        private AuditLogService                       $audit,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $session = $request->getAttribute(SessionInterface::class);
        assert($session instanceof SessionInterface);

        $userId      = $session->get('mfa_pending');
        $optionsJson = $session->get('webauthn_auth_options');

        if (!is_string($userId) || $userId === '' || !is_string($optionsJson) || $optionsJson === '') {
            return new JsonResponse(['error' => 'Keine ausstehende Authentifizierung.'], 400);
        }

        // Consume session state immediately (replay protection).
        $session->unset('webauthn_auth_options');

        $body = (string) $request->getBody();
        if ($body === '') {
            return new JsonResponse(['error' => 'Leerer Anfrage-Body.'], 400);
        }

        // Determine which credential was used.
        /** @var array<string, mixed> $parsed */
        $parsed   = json_decode($body, true) ?? [];
        $rawIdB64 = (string) ($parsed['rawId'] ?? $parsed['id'] ?? '');
        if ($rawIdB64 === '') {
            return new JsonResponse(['error' => 'Keine Credential-ID im Response.'], 422);
        }

        $credentialId = base64_decode(strtr($rawIdB64, '-_', '+/'), true);
        if ($credentialId === false) {
            return new JsonResponse(['error' => 'Ungültige Credential-ID.'], 422);
        }

        $source = $this->credentialRepo->findByCredentialId($credentialId);
        if (!$source instanceof \Webauthn\PublicKeyCredentialSource) {
            return new JsonResponse(['error' => 'Schlüssel nicht gefunden.'], 422);
        }

        try {
            $requestOptions = $this->webAuthn->deserializeRequestOptions($optionsJson);
            $updatedSource  = $this->webAuthn->parseAndValidateAuthentication($body, $source, $requestOptions, $userId);
        } catch (AuthenticatorResponseVerificationException $e) {
            return new JsonResponse(['error' => 'Verifizierung fehlgeschlagen: ' . $e->getMessage()], 422);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }

        // Persist updated counter + backup flags.
        $this->credentialRepo->updateAfterAuthentication($credentialId, $updatedSource->counter);

        // Complete login.
        $session->unset('mfa_pending');
        $session->unset('mfa_type');
        $session->regenerate();
        $session->set('user_id', $userId);
        $this->users->updateLastLoginAt($userId);
        $this->audit->recordLogin($request, $userId);

        return new JsonResponse(['ok' => true, 'redirect' => '/']);
    }
}

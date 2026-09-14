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
use TowerDNS\Application\Repository\WebAuthnCredentialRepositoryInterface;
use TowerDNS\Application\Services\WebAuthnService;
use TowerDNS\Domain\Auth\User;
use Webauthn\Exception\AuthenticatorResponseVerificationException;

/**
 * POST /profile/webauthn/register/finish
 *
 * Validates the attestation response from the browser and persists the
 * new credential.  The request body must be the JSON object produced by
 * navigator.credentials.create().
 */
final readonly class WebAuthnRegisterFinishHandler implements RequestHandlerInterface
{
    public function __construct(
        private WebAuthnService                       $webAuthn,
        private WebAuthnCredentialRepositoryInterface $credentialRepo,
        private TranslatorInterface                   $translator,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $session = $request->getAttribute(SessionInterface::class);
        assert($session instanceof SessionInterface);

        $optionsJson = $session->get('webauthn_register_options');
        $keyName     = $session->get('webauthn_register_name');

        if (!is_string($optionsJson) || $optionsJson === '' || !is_string($keyName) || $keyName === '') {
            return new JsonResponse(['error' => $this->translator->translate('webauthn.error.registration-pending')], 400);
        }

        // Consume the session state immediately (replay protection).
        $session->unset('webauthn_register_options');
        $session->unset('webauthn_register_name');

        $body = (string) $request->getBody();
        if ($body === '') {
            return new JsonResponse(['error' => $this->translator->translate('webauthn.error.invalid-request')], 400);
        }

        /** @var User $currentUser */
        $currentUser = $request->getAttribute(User::class);

        try {
            $creationOptions = $this->webAuthn->deserializeCreationOptions($optionsJson);
            $source          = $this->webAuthn->parseAndValidateRegistration($body, $creationOptions);
        } catch (AuthenticatorResponseVerificationException|\InvalidArgumentException) {
            return new JsonResponse(['error' => $this->translator->translate('webauthn.error.registration-failed')], 422);
        }

        $this->credentialRepo->save($currentUser->id, $keyName, $source);

        return new JsonResponse(['ok' => true]);
    }
}

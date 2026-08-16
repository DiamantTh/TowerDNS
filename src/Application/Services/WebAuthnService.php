<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Services;

use Symfony\Component\Serializer\SerializerInterface;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialSource;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * Domain service encapsulating WebAuthn registration and authentication ceremonies.
 *
 * The RP ID must match the effective domain (e.g. "example.com").
 * The allowed origin is derived as "https://{rpId}".
 */
final readonly class WebAuthnService
{
    public function __construct(
        private SerializerInterface $serializer,
        private string              $rpId,
        private string              $rpName,
    ) {}

    // ── Registration ──────────────────────────────────────────────────────────

    /**
     * Build creation options for a new credential.
     *
     * @param list<string> $excludedCredentialIds  Raw bytes of credentials to exclude
     */
    public function createRegistrationOptions(
        string $userId,
        string $userEmail,
        string $displayName,
        array  $excludedCredentialIds = [],
    ): PublicKeyCredentialCreationOptions {
        $rp   = new PublicKeyCredentialRpEntity($this->rpName, $this->rpId);
        $user = new PublicKeyCredentialUserEntity($userEmail, $userId, $displayName);

        $pubKeyCredParams = [
            PublicKeyCredentialParameters::createPk(-7),   // ES256
            PublicKeyCredentialParameters::createPk(-257), // RS256
        ];

        $excludeCredentials = array_map(
            static fn(string $id): PublicKeyCredentialDescriptor => new PublicKeyCredentialDescriptor(
                PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                $id,
            ),
            $excludedCredentialIds,
        );

        $selection = new AuthenticatorSelectionCriteria(
            authenticatorAttachment: AuthenticatorSelectionCriteria::AUTHENTICATOR_ATTACHMENT_NO_PREFERENCE,
            userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_PREFERRED,
        );

        return new PublicKeyCredentialCreationOptions(
            rp: $rp,
            user: $user,
            challenge: random_bytes(32),
            pubKeyCredParams: $pubKeyCredParams,
            authenticatorSelection: $selection,
            attestation: PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            excludeCredentials: $excludeCredentials,
            timeout: 60_000,
        );
    }

    /**
     * Deserialize and validate the registration response from the browser.
     *
     * @throws \InvalidArgumentException on invalid response type
     * @throws \Webauthn\Exception\AuthenticatorResponseVerificationException on verification failure
     */
    public function parseAndValidateRegistration(
        string                             $jsonResponse,
        PublicKeyCredentialCreationOptions $options,
    ): PublicKeyCredentialSource {
        /** @var PublicKeyCredential $credential */
        $credential = $this->serializer->deserialize($jsonResponse, PublicKeyCredential::class, 'json');

        if (!$credential->response instanceof AuthenticatorAttestationResponse) {
            throw new \InvalidArgumentException('Ungültige Antworttyp für die Registrierung.');
        }

        $factory = new CeremonyStepManagerFactory();
        $factory->setAllowedOrigins(['https://' . $this->rpId]);

        $record = AuthenticatorAttestationResponseValidator::create($factory->creationCeremony())
            ->check($credential->response, $options, $this->rpId);

        return PublicKeyCredentialSource::fromCredentialRecord($record);
    }

    // ── Authentication ────────────────────────────────────────────────────────

    /**
     * Build assertion options for authentication.
     *
     * @param list<string> $allowedCredentialIds  Raw bytes of credentials to allow
     */
    public function createAuthenticationOptions(array $allowedCredentialIds = []): PublicKeyCredentialRequestOptions
    {
        $allowCredentials = array_map(
            static fn(string $id): PublicKeyCredentialDescriptor => new PublicKeyCredentialDescriptor(
                PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                $id,
            ),
            $allowedCredentialIds,
        );

        return new PublicKeyCredentialRequestOptions(
            challenge: random_bytes(32),
            rpId: $this->rpId,
            allowCredentials: $allowCredentials,
            userVerification: PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_PREFERRED,
            timeout: 60_000,
        );
    }

    /**
     * Deserialize and validate the authentication response from the browser.
     *
     * @throws \InvalidArgumentException on invalid response type
     * @throws \Webauthn\Exception\AuthenticatorResponseVerificationException on verification failure
     */
    public function parseAndValidateAuthentication(
        string                            $jsonResponse,
        PublicKeyCredentialSource         $source,
        PublicKeyCredentialRequestOptions $options,
        ?string                           $userHandle = null,
    ): PublicKeyCredentialSource {
        /** @var PublicKeyCredential $credential */
        $credential = $this->serializer->deserialize($jsonResponse, PublicKeyCredential::class, 'json');

        if (!$credential->response instanceof AuthenticatorAssertionResponse) {
            throw new \InvalidArgumentException('Ungültige Antworttyp für die Authentifizierung.');
        }

        $factory = new CeremonyStepManagerFactory();
        $factory->setAllowedOrigins(['https://' . $this->rpId]);

        $record = AuthenticatorAssertionResponseValidator::create($factory->requestCeremony())
            ->check($source, $credential->response, $options, $this->rpId, $userHandle);

        return PublicKeyCredentialSource::fromCredentialRecord($record);
    }

    // ── Serialization helpers ─────────────────────────────────────────────────

    public function serializeCreationOptions(PublicKeyCredentialCreationOptions $options): string
    {
        return $this->serializer->serialize($options, 'json');
    }

    public function deserializeCreationOptions(string $json): PublicKeyCredentialCreationOptions
    {
        /** @var PublicKeyCredentialCreationOptions $opts */
        $opts = $this->serializer->deserialize($json, PublicKeyCredentialCreationOptions::class, 'json');
        return $opts;
    }

    public function serializeRequestOptions(PublicKeyCredentialRequestOptions $options): string
    {
        return $this->serializer->serialize($options, 'json');
    }

    public function deserializeRequestOptions(string $json): PublicKeyCredentialRequestOptions
    {
        /** @var PublicKeyCredentialRequestOptions $opts */
        $opts = $this->serializer->deserialize($json, PublicKeyCredentialRequestOptions::class, 'json');
        return $opts;
    }
}

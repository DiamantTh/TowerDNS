<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Application\Services;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Uid\Uuid;
use TowerDNS\Application\Services\WebAuthnService;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\Exception\AuthenticatorResponseVerificationException;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\TrustPath\EmptyTrustPath;

/** @psalm-api Runtime discovery by PHPUnit or local module loading is not statically visible. */
final class WebAuthnServiceTest extends TestCase
{
    public function testRegistrationPrefersResidentCredentialsAndRequiresUv(): void
    {
        $general   = $this->service()->createRegistrationOptions('user-1', 'person@example.test', 'Person');
        $selection = $general->authenticatorSelection;
        self::assertInstanceOf(AuthenticatorSelectionCriteria::class, $selection);
        self::assertSame(AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_PREFERRED, $selection->residentKey);
        self::assertSame(AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED, $selection->userVerification);
        self::assertNull($selection->authenticatorAttachment);

        $hardware          = $this->service()->createRegistrationOptions('user-1', 'person@example.test', 'Person', hardwareSecurityKey: true);
        $hardwareSelection = $hardware->authenticatorSelection;
        self::assertInstanceOf(AuthenticatorSelectionCriteria::class, $hardwareSelection);
        self::assertSame(AuthenticatorSelectionCriteria::AUTHENTICATOR_ATTACHMENT_CROSS_PLATFORM, $hardwareSelection->authenticatorAttachment);
        self::assertSame(AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_PREFERRED, $hardwareSelection->residentKey);
    }

    public function testUsernameFirstAuthenticationUsesAllowListAndRequiredUv(): void
    {
        $id      = 'raw-credential-id';
        $options = $this->service()->createAuthenticationOptions([$id], transportsByCredentialId: [$id => ['usb', 'nfc']]);

        self::assertSame(PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED, $options->userVerification);
        self::assertCount(1, $options->allowCredentials);
        self::assertSame($id, $options->allowCredentials[0]->id);
        self::assertSame(['usb', 'nfc'], $options->allowCredentials[0]->transports);
    }

    public function testStoredOldCeremonyOptionsWithoutRequiredUvAreRejected(): void
    {
        $service = $this->service();
        $options = $service->createAuthenticationOptions([], PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_PREFERRED);

        $this->expectException(\InvalidArgumentException::class);
        $service->parseAndValidateAuthentication('{}', new CredentialRecord(
            'credential',
            'public-key',
            [],
            'none',
            EmptyTrustPath::create(),
            Uuid::fromString('00000000-0000-0000-0000-000000000000'),
            'public-key',
            'user-1',
            0,
        ), $options);
    }

    public function testAssertionWithInvalidChallengeIsRejected(): void
    {
        $service = $this->service();
        $options = $service->createAuthenticationOptions(['credential-id']);
        $this->expectException(AuthenticatorResponseVerificationException::class);
        $service->parseAndValidateAuthentication(
            $this->assertionJson(random_bytes(32), 'https://example.test', 'example.test', 0x05),
            $this->credentialRecord(),
            $options,
            'user-1',
        );
    }

    public function testAssertionFromAnotherOriginIsRejected(): void
    {
        $service = $this->service();
        $options = $service->createAuthenticationOptions(['credential-id']);

        $this->expectException(AuthenticatorResponseVerificationException::class);
        $service->parseAndValidateAuthentication(
            $this->assertionJson($options->challenge, 'https://attacker.test', 'example.test', 0x05),
            $this->credentialRecord(),
            $options,
            'user-1',
        );
    }

    public function testAssertionWithDifferentRpIdHashIsRejected(): void
    {
        $service = $this->service();
        $options = $service->createAuthenticationOptions(['credential-id']);

        $this->expectException(AuthenticatorResponseVerificationException::class);
        $service->parseAndValidateAuthentication(
            $this->assertionJson($options->challenge, 'https://example.test', 'attacker.test', 0x05),
            $this->credentialRecord(),
            $options,
            'user-1',
        );
    }

    public function testAssertionWithoutUserVerificationIsRejected(): void
    {
        $service = $this->service();
        $options = $service->createAuthenticationOptions(['credential-id']);

        $this->expectException(AuthenticatorResponseVerificationException::class);
        $service->parseAndValidateAuthentication(
            $this->assertionJson($options->challenge, 'https://example.test', 'example.test', 0x01),
            $this->credentialRecord(),
            $options,
            'user-1',
        );
    }

    private function credentialRecord(): CredentialRecord
    {
        return new CredentialRecord(
            'credential-id',
            'public-key',
            [],
            'none',
            EmptyTrustPath::create(),
            Uuid::fromString('00000000-0000-0000-0000-000000000000'),
            'x',
            'user-1',
            0,
        );
    }

    private function assertionJson(string $challenge, string $origin, string $rpId, int $flags): string
    {
        $credentialId = 'credential-id';
        $clientData   = json_encode([
            'type'        => 'webauthn.get',
            'challenge'   => $this->base64Url($challenge),
            'origin'      => $origin,
            'crossOrigin' => false,
        ], JSON_THROW_ON_ERROR);
        $authData = hash('sha256', $rpId, true) . chr($flags) . pack('N', 1);
        $id       = $this->base64Url($credentialId);

        return json_encode([
            'id'       => $id,
            'rawId'    => $id,
            'type'     => 'public-key',
            'response' => [
                'clientDataJSON'    => $this->base64Url($clientData),
                'authenticatorData' => $this->base64Url($authData),
                'signature'         => $this->base64Url('invalid-test-signature'),
                'userHandle'        => $this->base64Url('user-1'),
            ],
        ], JSON_THROW_ON_ERROR);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function service(): WebAuthnService
    {
        return new WebAuthnService($this->serializer(), 'example.test', 'TowerDNS');
    }

    private function serializer(): SerializerInterface
    {
        return new WebauthnSerializerFactory(new AttestationStatementSupportManager([
            new NoneAttestationStatementSupport(),
        ]))->create();
    }
}

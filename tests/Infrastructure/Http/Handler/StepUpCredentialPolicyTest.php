<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Http\Handler;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Uri;
use Laminas\Translator\TranslatorInterface;
use Mezzio\Csrf\CsrfGuardInterface;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Session\Session;
use Mezzio\Session\SessionInterface;
use Mezzio\Template\TemplateRendererInterface;
use OTPHP\TOTP;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Uid\Uuid;
use TowerDNS\Application\Contracts\CredentialEncryptorInterface;
use TowerDNS\Application\DTO\StepUpAction;
use TowerDNS\Application\Repository\AuditLogRepositoryInterface;
use TowerDNS\Application\Repository\SystemSettingsRepositoryInterface;
use TowerDNS\Application\Repository\UserRepositoryInterface;
use TowerDNS\Application\Repository\WebAuthnCredentialRepositoryInterface;
use TowerDNS\Application\Services\AuditLogService;
use TowerDNS\Application\Services\StepUpProofService;
use TowerDNS\Application\Services\TotpSecretService;
use TowerDNS\Application\Services\TotpService;
use TowerDNS\Application\Services\WebAuthnService;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Infrastructure\Http\Handler\StepUpHandler;
use TowerDNS\Infrastructure\Http\SessionSecurity;
use TowerDNS\Infrastructure\Persistence\DbalStepUpProofNonceRepository;
use TowerDNS\Infrastructure\Persistence\DbalTotpCredentialRepository;
use TowerDNS\Infrastructure\Persistence\SchemaManager;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\TrustPath\EmptyTrustPath;

/** @psalm-api Runtime discovery by PHPUnit or local module loading is not statically visible. */
final class StepUpCredentialPolicyTest extends TestCase
{
    public function testTotpDeletionRequiresAnotherTotpCredentialAtTwoToOne(): void
    {
        $targetSecret = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
        $otherSecret  = array_find(
            ['KRSXG5DSNFXGOIDBMJSGCY3QOZUWY4R2', 'MFRGGZDFMZTWQ2LKNNWG23TPOI======'],
            fn(string $candidate): bool => $this->code($candidate) !== $this->code($targetSecret),
        );
        if (!is_string($otherSecret)) {
            self::fail('The test needs separate current TOTP codes for the two credentials.');
        }
        [$handler, $session, $user, $secrets, $clock, $proofs] = $this->fixture([$targetSecret, $otherSecret], []);
        $registered                                            = $secrets->list($user->id);
        $targetId                                              = array_values(array_filter($registered, static fn(array $entry): bool => $entry['label'] === 'Authenticator 1'))[0]['id'];
        $otherId                                               = array_values(array_filter($registered, static fn(array $entry): bool => $entry['label'] === 'Authenticator 2'))[0]['id'];
        $session                                               = new SessionSecurity($clock)->beginStepUp($session, $user->id, StepUpAction::PROFILE_TOTP_DELETE, hash('sha256', $targetId), null);

        $selfResponse = $handler->handle($this->totpRequest($user, $session, $this->code($targetSecret)));
        self::assertSame(401, $selfResponse->getStatusCode());
        self::assertFalse($session->has('step_up_proof'));

        $otherResponse = $handler->handle($this->totpRequest($user, $session, $this->code($otherSecret)));
        self::assertSame(302, $otherResponse->getStatusCode());
        $proof = new SessionSecurity($clock)->availableStepUpProof($session, $proofs);
        self::assertNotNull($proof);
        self::assertSame(hash('sha256', $otherId), $proof->credentialIdHash);
    }

    public function testTotpCannotStepUpFidoRemoval(): void
    {
        [$handler, $session, $user, $secrets, $clock] = $this->fixture(['JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP'], []);
        $session                                      = new SessionSecurity($clock)->beginStepUp($session, $user->id, StepUpAction::PROFILE_WEBAUTHN_DELETE, hash('sha256', 'fido-target'), null);

        $response = $handler->handle($this->totpRequest($user, $session, $this->code('JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP')));
        self::assertSame(403, $response->getStatusCode());
        self::assertFalse($session->has('step_up_proof'));
        self::assertSame(1, $secrets->count($user->id));
    }

    public function testAdminCredentialRecoveryStepUpOnlyOffersAdministratorFidoWithUvRequired(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $schema     = new SchemaManager($connection);
        $schema->createTablesIfNotExist();
        $schema->seedSystemRoles();
        $userId = '33333333-3333-4333-8333-333333333333';
        $schema->seedFirstUser($userId, 'admin@example.test', 'password-hash');
        $user        = new User($userId, 'admin@example.test');
        $clock       = $this->clock();
        $session     = new SessionSecurity($clock)->beginStepUp(new Session([]), $userId, StepUpAction::IAM_USER_WEBAUTHN_REVOKE, StepUpAction::iamUserWebAuthnCredentialTarget('target-user', 'lost-key'), null);
        $credentials = $this->createMock(WebAuthnCredentialRepositoryInterface::class);
        $credentials->method('findByUserId')->with($userId)->willReturn([$this->fidoRow('administrator-key', $userId)]);
        $proofs   = new StepUpProofService(str_repeat('k', 32), $clock, new DbalStepUpProofNonceRepository($connection));
        $rendered = [];
        $renderer = $this->createMock(TemplateRendererInterface::class);
        $renderer->method('render')->willReturnCallback(static function (string $_template, array $variables) use (&$rendered): string {
            $rendered = $variables;
            return '<html></html>';
        });
        $handler = $this->handler($connection, $clock, $credentials, $this->createMock(UserRepositoryInterface::class), proofs: $proofs, renderer: $renderer);

        $showResponse = $handler->handle($this->request('/security/step-up', $user, $session)->withMethod('GET'));
        self::assertSame(200, $showResponse->getStatusCode());
        self::assertTrue($rendered['passkeyAvailable']);
        self::assertFalse($rendered['totpAvailable']);
        self::assertFalse($rendered['passwordAvailable']);

        $totpResponse = $handler->handle($this->request('/security/step-up/totp', $user, $session)->withParsedBody(['csrf_token' => 'csrf', 'code' => '00000000']));
        self::assertSame(403, $totpResponse->getStatusCode());
        $passwordResponse = $handler->handle($this->request('/security/step-up/password', $user, $session)->withParsedBody(['csrf_token' => 'csrf', 'password' => 'password']));
        self::assertSame(403, $passwordResponse->getStatusCode());

        $beginResponse = $handler->handle($this->request('/security/step-up/webauthn/begin', $user, $session));
        self::assertSame(200, $beginResponse->getStatusCode());
        $options = json_decode((string) $beginResponse->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('required', $options['userVerification']);
        self::assertSame(['administrator-key'], array_map(static function (array $entry): string {
            $credentialId = base64_decode(strtr($entry['id'], '-_', '+/'), true);
            return $credentialId === false ? '' : $credentialId;
        }, $options['allowCredentials']));
    }

    public function testFidoTwoToOneStepUpOptionsExcludeTheTargetCredential(): void
    {
        $clock      = $this->clock();
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $schema     = new SchemaManager($connection);
        $schema->createTablesIfNotExist();
        $schema->seedSystemRoles();
        $userId = '33333333-3333-4333-8333-333333333333';
        $schema->seedFirstUser($userId, 'person@example.test', 'password-hash');
        $user        = new User($userId, 'person@example.test');
        $session     = new SessionSecurity($clock)->beginStepUp(new Session([]), $userId, StepUpAction::PROFILE_WEBAUTHN_DELETE, hash('sha256', 'target-key'), null);
        $credentials = $this->createMock(WebAuthnCredentialRepositoryInterface::class);
        $credentials->method('findByUserId')->with($userId)->willReturn([
            $this->fidoRow('target-key', $userId),
            $this->fidoRow('other-key', $userId),
        ]);
        $handler  = $this->handler($connection, $clock, $credentials, $this->createMock(UserRepositoryInterface::class));
        $response = $handler->handle($this->request('/security/step-up/webauthn/begin', $user, $session));
        self::assertSame(200, $response->getStatusCode());
        $options = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['other-key'], array_map(static function (array $entry): string {
            $credentialId = base64_decode(strtr($entry['id'], '-_', '+/'), true);
            return $credentialId === false ? '' : $credentialId;
        }, $options['allowCredentials']));
    }

    /**
     * @param list<string>           $secrets
     * @param list<string>           $fidoIds
     *
     * @return array{StepUpHandler, Session, User, TotpSecretService, ClockInterface, StepUpProofService}
     */
    private function fixture(array $secrets, array $fidoIds): array
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $schema     = new SchemaManager($connection);
        $schema->createTablesIfNotExist();
        $schema->seedSystemRoles();
        $userId = '33333333-3333-4333-8333-333333333333';
        $schema->seedFirstUser($userId, 'person@example.test', 'password-hash');
        $user     = new User($userId, 'person@example.test');
        $clock    = $this->clock();
        $settings = $this->createMock(SystemSettingsRepositoryInterface::class);
        $settings->method('get')->willReturnCallback(static fn(string $key, mixed $default = null): mixed => $key === 'security.totp.max_credentials_per_user' ? 5 : $default);
        $totp = new TotpSecretService(new DbalTotpCredentialRepository($connection, $clock), new TotpService($clock), new class implements CredentialEncryptorInterface {
            #[\Override] public function encrypt(string $plaintext): string
            {
                return base64_encode($plaintext);
            }
            #[\Override] public function decrypt(string $ciphertext): string
            {
                $value = base64_decode($ciphertext, true);
                if (!is_string($value)) {
                    throw new \RuntimeException('Invalid ciphertext.');
                } return $value;
            }
            #[\Override] public function wipe(string &$plaintext): void
            {
                $plaintext = '';
            }
        }, $settings);
        foreach ($secrets as $index => $secret) {
            if ($secret === '') {
                throw new \InvalidArgumentException('Test TOTP secrets must not be empty.');
            }
            $totp->enable($userId, $secret, 'Authenticator ' . ($index + 1));
        }
        $credentials = $this->createMock(WebAuthnCredentialRepositoryInterface::class);
        $credentials->method('findByUserId')->with($userId)->willReturn(array_map(fn(string $id): array => $this->fidoRow($id, $userId), $fidoIds));
        $proofs = new StepUpProofService(str_repeat('k', 32), $clock, new DbalStepUpProofNonceRepository($connection));
        return [$this->handler($connection, $clock, $credentials, $this->createMock(UserRepositoryInterface::class), $totp, $proofs), new Session([]), $user, $totp, $clock, $proofs];
    }

    private function handler(Connection $connection, ClockInterface $clock, WebAuthnCredentialRepositoryInterface $credentials, UserRepositoryInterface $users, ?TotpSecretService $totp = null, ?StepUpProofService $proofs = null, ?TemplateRendererInterface $renderer = null): StepUpHandler
    {
        $audit = $this->createMock(AuditLogRepositoryInterface::class);
        $audit->method('append');
        $settings = $this->createMock(SystemSettingsRepositoryInterface::class);
        $settings->method('get')->willReturn(5);
        $totp ??= new TotpSecretService(new DbalTotpCredentialRepository($connection, $clock), new TotpService($clock), new class implements CredentialEncryptorInterface {
            #[\Override] public function encrypt(string $plaintext): string
            {
                return base64_encode($plaintext);
            }
            #[\Override] public function decrypt(string $ciphertext): string
            {
                $value = base64_decode($ciphertext, true);
                if (!is_string($value)) {
                    throw new \RuntimeException('Invalid ciphertext.');
                } return $value;
            }
            #[\Override] public function wipe(string &$plaintext): void
            {
                $plaintext = '';
            }
        }, $settings);
        $proofs ??= new StepUpProofService(str_repeat('k', 32), $clock, new DbalStepUpProofNonceRepository($connection));
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('translate')->willReturnArgument(0);
        return new StepUpHandler(
            $renderer ?? $this->createMock(TemplateRendererInterface::class),
            $totp,
            new WebAuthnService($this->serializer(), 'example.test', 'TowerDNS'),
            $credentials,
            new SessionSecurity($clock),
            $proofs,
            new AuditLogService($audit),
            $translator,
            new Psr16Cache(new ArrayAdapter()),
            $users,
        );
    }

    private function request(string $path, User $user, SessionInterface $session): ServerRequest
    {
        $guard = $this->createMock(CsrfGuardInterface::class);
        $guard->method('validateToken')->willReturn(true);
        $guard->method('generateToken')->willReturn('csrf');
        return new ServerRequest()->withMethod('POST')->withUri(new Uri('https://example.test' . $path))
            ->withParsedBody(['csrf_token' => 'csrf'])
            ->withAttribute(User::class, $user)
            ->withAttribute(SessionInterface::class, $session)
            ->withAttribute(CsrfMiddleware::GUARD_ATTRIBUTE, $guard);
    }

    private function totpRequest(User $user, SessionInterface $session, string $code): ServerRequest
    {
        $request = $this->request('/security/step-up/totp', $user, $session);
        return $request->withParsedBody(['csrf_token' => 'csrf', 'code' => $code]);
    }

    private function code(string $secret): string
    {
        if ($secret === '') {
            throw new \InvalidArgumentException('Test TOTP secrets must not be empty.');
        }
        return TOTP::create($secret, 30, 'sha512', 8, 0, $this->clock())->now();
    }

    private function clock(): ClockInterface
    {
        return new class implements ClockInterface {
            #[\Override] public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('@1768500000');
            }
        };
    }

    /** @return array<string, mixed> */
    private function fidoRow(string $id, string $userId): array
    {
        $source = new CredentialRecord($id, 'public-key', [], 'none', EmptyTrustPath::create(), Uuid::fromString('00000000-0000-0000-0000-000000000000'), 'public-key', $userId, 0);
        return ['credential_id' => $id, 'name' => 'Key', 'created_at' => '2026-01-01 00:00:00', 'last_used_at' => null,
            'attachment'        => 'cross-platform', 'aaguid' => '00000000-0000-0000-0000-000000000000', 'transports' => [], 'backup_eligible' => false,
            'backup_state'      => false, 'source' => $source];
    }

    private function serializer(): SerializerInterface
    {
        return new WebauthnSerializerFactory(new AttestationStatementSupportManager([new NoneAttestationStatementSupport()]))->create();
    }
}

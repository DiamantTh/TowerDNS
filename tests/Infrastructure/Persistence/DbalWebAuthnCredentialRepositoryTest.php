<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Persistence;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Uid\Uuid;
use TowerDNS\Application\Exception\WebAuthnCredentialLimitException;
use TowerDNS\Infrastructure\Persistence\DbalWebAuthnCredentialRepository;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\TrustPath\EmptyTrustPath;

/** @psalm-api Runtime discovery by PHPUnit or local module loading is not statically visible. */
final class DbalWebAuthnCredentialRepositoryTest extends TestCase
{
    public function testOpaqueUserHandleIsUniquePersistentAndStableAcrossRepositoryInstances(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE users (id TEXT PRIMARY KEY, active BOOLEAN NOT NULL, webauthn_user_handle BLOB NULL UNIQUE, webauthn_user_handle_rp_id TEXT NULL)');
        $connection->executeStatement('CREATE TABLE webauthn_credentials (credential_id BLOB PRIMARY KEY, user_id TEXT NOT NULL)');
        $connection->insert('users', ['id' => 'internal-user-a', 'active' => true, 'webauthn_user_handle' => null]);
        $connection->insert('users', ['id' => 'internal-user-b', 'active' => true, 'webauthn_user_handle' => null]);

        $firstRepository     = new DbalWebAuthnCredentialRepository($connection, $this->serializer());
        $handleA             = $firstRepository->getOrCreateUserHandle('internal-user-a', 'dns.example.test');
        $secondRepository    = new DbalWebAuthnCredentialRepository($connection, $this->serializer());
        $handleAAfterRestart = $secondRepository->getOrCreateUserHandle('internal-user-a', 'dns.example.test');
        $handleB             = $secondRepository->getOrCreateUserHandle('internal-user-b', 'dns.example.test');

        self::assertSame(64, strlen($handleA));
        self::assertNotSame('internal-user-a', $handleA);
        self::assertSame($handleA, $handleAAfterRestart);
        self::assertSame($handleA, $connection->fetchOne('SELECT webauthn_user_handle FROM users WHERE id = ?', ['internal-user-a']));
        self::assertSame('dns.example.test', $connection->fetchOne('SELECT webauthn_user_handle_rp_id FROM users WHERE id = ?', ['internal-user-a']));
        self::assertSame(64, strlen($handleB));
        self::assertNotSame($handleA, $handleB);
        $rotatedHandleB = $secondRepository->getOrCreateUserHandle('internal-user-b', 'new-dns.example.test');
        self::assertSame(64, strlen($rotatedHandleB));
        self::assertNotSame($handleB, $rotatedHandleB);
        self::assertSame('new-dns.example.test', $connection->fetchOne('SELECT webauthn_user_handle_rp_id FROM users WHERE id = ?', ['internal-user-b']));

        $connection->insert('webauthn_credentials', ['credential_id' => 'known-credential', 'user_id' => 'internal-user-a']);
        try {
            $secondRepository->getOrCreateUserHandle('internal-user-a', 'new-dns.example.test');
            self::fail('The relying party must not change after credentials are registered.');
        } catch (\DomainException) {
            self::assertSame($handleA, $secondRepository->getOrCreateUserHandle('internal-user-a', 'dns.example.test'));
        }

        $connection->update('users', ['active' => false], ['id' => 'internal-user-b']);
        $this->expectException(\DomainException::class);
        $secondRepository->getOrCreateUserHandle('internal-user-b', 'new-dns.example.test');
    }

    public function testReadsCredentialsSerializedByThePreviousCredentialSourceFormat(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement(
            'CREATE TABLE webauthn_credentials (
                credential_id BLOB PRIMARY KEY,
                user_id TEXT NOT NULL,
                name TEXT NOT NULL,
                data TEXT NOT NULL,
                attachment TEXT NULL,
                created_at TEXT NOT NULL,
                last_used_at TEXT NULL
            )',
        );

        // The WebAuthn serializer persisted this JSON shape before 5.3 as
        // PublicKeyCredentialSource. It is intentionally class-name-free and
        // can therefore be read as the current CredentialRecord directly.
        $legacyJson = '{"publicKeyCredentialId":"Y3JlZGVudGlhbC1pZA","type":"public-key","transports":[],"attestationType":"none","trustPath":[],"aaguid":"00000000-0000-0000-0000-000000000000","credentialPublicKey":"cHVibGljLWtleQ","userHandle":"dXNlcg","counter":1}';
        $connection->insert('webauthn_credentials', [
            'credential_id' => 'credential-id',
            'user_id'       => 'user-1',
            'name'          => 'Existing passkey',
            'data'          => $legacyJson,
            'created_at'    => '2026-01-01 00:00:00',
            'last_used_at'  => null,
        ]);

        $repository = new DbalWebAuthnCredentialRepository($connection, $this->serializer());
        $record     = $repository->findByCredentialId('credential-id');

        self::assertInstanceOf(CredentialRecord::class, $record);
        self::assertSame(1, $record->counter);
        self::assertSame('credential-id', $record->publicKeyCredentialId);
        self::assertSame('user', $record->userHandle, 'Legacy user handles remain bound to their saved credential record.');
        self::assertSame(1, $repository->countAll());
        self::assertSame(1, $repository->countByUserId('user-1'));
        self::assertInstanceOf(CredentialRecord::class, $repository->findByCredentialIdForUser('credential-id', 'user-1'));
        self::assertNull($repository->findByCredentialIdForUser('credential-id', 'someone-else'));

        try {
            $repository->save('user-1', 'Second key', $record, maxCredentials: 1);
            self::fail('The configured WebAuthn limit must be enforced.');
        } catch (WebAuthnCredentialLimitException) {
            self::assertSame(1, $repository->countByUserId('user-1'));
        }

        $replacement = new CredentialRecord('replacement-id', 'public-key', [], 'none', EmptyTrustPath::create(), Uuid::fromString('00000000-0000-0000-0000-000000000000'), 'public-key', 'user-1', 0);
        $repository->saveDuringRecovery('user-1', 'Recovery key', $replacement, 'cross-platform', 2);
        self::assertSame(2, $repository->countByUserId('user-1'), 'Recovery must keep the old factor until a replacement has been verified.');
        try {
            $repository->save('user-1', 'Third key', $replacement, maxCredentials: 1);
            self::fail('Ordinary enrollment must remain limited while recovery has its temporary slot.');
        } catch (WebAuthnCredentialLimitException) {
            self::assertSame(2, $repository->countByUserId('user-1'));
        }

        $record->counter = 2;
        $repository->updateAfterAuthentication($record);
        self::assertSame(2, $repository->findByCredentialId('credential-id')?->counter);
    }

    private function serializer(): SerializerInterface
    {
        $attestationStatements = new AttestationStatementSupportManager([
            new NoneAttestationStatementSupport(),
        ]);

        return new WebauthnSerializerFactory($attestationStatements)->create();
    }
}

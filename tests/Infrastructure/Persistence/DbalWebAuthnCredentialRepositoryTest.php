<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Persistence;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\SerializerInterface;
use TowerDNS\Application\Exception\WebAuthnCredentialLimitException;
use TowerDNS\Infrastructure\Persistence\DbalWebAuthnCredentialRepository;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;

/** @psalm-api Runtime discovery by PHPUnit or local module loading is not statically visible. */
final class DbalWebAuthnCredentialRepositoryTest extends TestCase
{
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

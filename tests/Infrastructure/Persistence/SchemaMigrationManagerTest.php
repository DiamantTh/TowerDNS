<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Persistence;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Index\IndexType;
use PHPUnit\Framework\TestCase;
use TowerDNS\Infrastructure\Persistence\Migrations\Version20261004000100;
use TowerDNS\Infrastructure\Persistence\SchemaManager;
use TowerDNS\Infrastructure\Persistence\SchemaMigrationManager;

/** @psalm-api Runtime discovery by PHPUnit or local module loading is not statically visible. */
final class SchemaMigrationManagerTest extends TestCase
{
    public function testFreshDatabaseIsMigratedAndRepeatedRunsAreNoOps(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $manager    = $this->manager($connection);

        $status = $manager->migrate();
        self::assertSame([], $status->pending);
        self::assertTrue($status->schemaCurrent);
        self::assertSame(4, (int) $connection->fetchOne('SELECT COUNT(*) FROM towerdns_schema_migrations'));
        self::assertSame(6, (int) $connection->fetchOne('SELECT COUNT(*) FROM roles'));

        $second = $manager->migrate();
        self::assertSame([], $second->pending);
        self::assertSame(4, (int) $connection->fetchOne('SELECT COUNT(*) FROM towerdns_schema_migrations'));
    }

    public function testCurrentSchemaIsBaselinedWithoutChangingExistingData(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $schema     = new SchemaManager($connection);
        $schema->createTablesIfNotExist();
        $schema->seedSystemRoles();
        $schema->seedSystemSettingsDefaults();
        $schema->seedFirstUser('user-1', 'admin@example.test', password_hash('safe-password', PASSWORD_ARGON2ID), 'Admin');
        $connection->update('users', ['locale' => 'de-DE'], ['id' => 'user-1']);

        $manager = $this->manager($connection);
        $status  = $manager->status();
        self::assertTrue($status->canBaseline);
        $manager->migrate();

        self::assertSame('de-DE', $connection->fetchOne('SELECT locale FROM users WHERE id = ?', ['user-1']));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM accounts WHERE personal_user_id = ?', ['user-1']));
    }

    public function testKnownLegacyColumnIsAddedAndLocaleIsBackfilled(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $schema     = new SchemaManager($connection);
        $schema->createTablesIfNotExist();
        $schema->seedSystemRoles();
        $schema->seedSystemSettingsDefaults();
        $schema->seedFirstUser('user-2', 'legacy@example.test', password_hash('safe-password', PASSWORD_ARGON2ID), 'Legacy');
        $connection->update('users', ['locale' => 'de-DE'], ['id' => 'user-2']);
        $connection->executeStatement('ALTER TABLE users DROP COLUMN language');

        $status = $this->manager($connection)->migrate();

        self::assertSame([], $status->pending);
        self::assertSame('de-DE', $connection->fetchOne('SELECT language FROM users WHERE id = ?', ['user-2']));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM accounts WHERE personal_user_id = ?', ['user-2']));
    }

    public function testAuthMigrationMovesLegacyEncryptedTotpSecretWithoutLosingIt(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $schema     = new SchemaManager($connection);
        $schema->createTablesIfNotExist();
        $schema->seedSystemRoles();
        $schema->seedSystemSettingsDefaults();
        $schema->seedFirstUser('legacy-totp-user', 'legacy-totp@example.test', password_hash('safe-password', PASSWORD_ARGON2ID), 'Legacy TOTP');
        $encrypted = 'versioned-encrypted-secret-that-must-survive';
        $connection->update('users', ['totp_secret_encrypted' => $encrypted], ['id' => 'legacy-totp-user']);

        $manager = $this->manager($connection);
        $manager->migrate();
        $connection->executeStatement('DROP TABLE totp_credentials');
        $connection->executeStatement('ALTER TABLE webauthn_credentials DROP COLUMN attachment');
        $connection->delete(SchemaMigrationManager::METADATA_TABLE, ['version' => Version20261004000100::class]);

        $status = $manager->migrate();

        self::assertTrue($status->schemaCurrent);
        self::assertSame($encrypted, $connection->fetchOne('SELECT secret_encrypted FROM totp_credentials WHERE user_id = ?', ['legacy-totp-user']));
        self::assertNull($connection->fetchOne('SELECT totp_secret_encrypted FROM users WHERE id = ?', ['legacy-totp-user']));
        self::assertSame('Migrated authenticator', $connection->fetchOne('SELECT label FROM totp_credentials WHERE user_id = ?', ['legacy-totp-user']));
    }

    public function testLegacyAccountTypeGapIsReportedWithoutFailingStatusInspection(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $schema     = new SchemaManager($connection);
        $schema->createTablesIfNotExist();
        $schema->seedSystemRoles();
        $schema->seedSystemSettingsDefaults();
        $schema->seedFirstUser('user-legacy-account', 'legacy-account@example.test', password_hash('safe-password', PASSWORD_ARGON2ID), 'Legacy');
        $connection->executeStatement('ALTER TABLE accounts DROP COLUMN account_type');

        $status = $this->manager($connection)->status();

        self::assertFalse($status->schemaCurrent);
        self::assertStringContainsString('missing column accounts.account_type', implode(' ', $status->schemaIssues));
    }

    public function testStatusDoesNotCreateMetadataOrChangeSchema(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        $status = $this->manager($connection)->status();

        self::assertFalse($status->metadataInitialized);
        self::assertSame([], $connection->createSchemaManager()->introspectTableNames());
    }

    public function testUnknownExecutedMigrationBlocksStatusAndUpgrade(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $schema     = new SchemaManager($connection);
        $schema->createTablesIfNotExist();
        $schema->seedSystemRoles();
        $schema->seedSystemSettingsDefaults();
        $schema->seedFirstUser('user-3', 'unknown@example.test', password_hash('safe-password', PASSWORD_ARGON2ID), 'Unknown');
        $connection->executeStatement(
            'CREATE TABLE towerdns_schema_migrations (version VARCHAR(191) NOT NULL, executed_at DATETIME NULL, execution_time INTEGER NULL, PRIMARY KEY (version))',
        );
        $connection->insert('towerdns_schema_migrations', [
            'version'        => 'Version20990101000000',
            'executed_at'    => '2099-01-01 00:00:00',
            'execution_time' => 0,
        ]);

        $manager = $this->manager($connection);
        $status  = $manager->status();
        self::assertFalse($status->schemaCurrent);
        self::assertStringContainsString('unknown executed migration', implode(' ', $status->schemaIssues));

        $this->expectException(\RuntimeException::class);
        $manager->migrate();
    }

    public function testPartialSchemaStatusReportsMissingTablesWithoutChangingTheDatabase(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        new SchemaManager($connection)->createTablesIfNotExist();
        $connection->executeStatement('DROP TABLE system_settings');

        $status = $this->manager($connection)->status();

        self::assertFalse($status->schemaCurrent);
        self::assertStringContainsString('missing table system_settings', implode(' ', $status->schemaIssues));
        self::assertFalse($status->metadataInitialized);
    }

    public function testSemanticallyMatchingLegacyIndexIsNotDuplicatedDuringSchemaMerge(): void
    {
        $connection    = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $schemaManager = new SchemaManager($connection);
        $schemaManager->createTablesIfNotExist();
        $databaseSchema = $connection->createSchemaManager()->introspectSchema()
            ->edit()
            ->modifyTableByUnquotedName('accounts', static function (\Doctrine\DBAL\Schema\TableEditor $editor): void {
                $editor->dropIndexByUnquotedName('uq_accounts_slug');
                $editor->addIndex(
                    Index::editor()
                        ->setUnquotedName('legacy_accounts_slug')
                        ->setType(IndexType::UNIQUE)
                        ->setUnquotedColumnNames('slug')
                        ->create(),
                );
            })
            ->create();
        $accounts = $databaseSchema->getTable('accounts');

        $schemaManager->mergeCanonicalSchema($databaseSchema);

        self::assertFalse($accounts->hasIndex('uq_accounts_slug'));
        self::assertTrue($accounts->hasIndex('legacy_accounts_slug'));
    }

    private function manager(\Doctrine\DBAL\Connection $connection): SchemaMigrationManager
    {
        return new SchemaMigrationManager($connection, sys_get_temp_dir() . '/towerdns-test-' . bin2hex(random_bytes(8)) . '.lock');
    }
}

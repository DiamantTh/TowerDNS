<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use TowerDNS\Infrastructure\Clock\SystemClock;
use TowerDNS\Infrastructure\Installation\FreshInstallBootstrapper;
use TowerDNS\Infrastructure\Installation\FreshInstallBootstrapRequest;
use TowerDNS\Infrastructure\Persistence\DbalStepUpProofNonceRepository;
use TowerDNS\Infrastructure\Persistence\DbalUserRepository;
use TowerDNS\Infrastructure\Persistence\SchemaManager;
use TowerDNS\Infrastructure\Persistence\SchemaMigrationLock;
use TowerDNS\Infrastructure\Persistence\SchemaMigrationLockedException;
use TowerDNS\Infrastructure\Persistence\SchemaMigrationManager;
use TowerDNS\Infrastructure\Persistence\SqliteConnectionConfigurator;

/**
 * Runs the migration invariants against SQLite and, when configured, real
 * MariaDB and PostgreSQL servers. Non-SQLite databases must be disposable.
 * @psalm-api Runtime discovery by PHPUnit or local module loading is not statically visible.
 */
final class SchemaMigrationDatabaseIntegrationTest extends TestCase
{
    public function testFreshInstallAndRepeatedMigrationAcrossConfiguredDatabases(): void
    {
        $this->forEachDatabase(function (string $backend, Connection $connection): void {
            $manager = $this->manager($connection, $backend);

            $first = $manager->migrate();
            self::assertSame([], $first->pending, $backend);
            self::assertTrue($first->schemaCurrent, $backend);
            self::assertSame(5, (int) $connection->fetchOne('SELECT COUNT(*) FROM towerdns_schema_migrations'), $backend);
            self::assertSame(6, (int) $connection->fetchOne('SELECT COUNT(*) FROM roles'), $backend);

            $second = $manager->migrate();
            self::assertSame([], $second->pending, $backend);
            self::assertSame(5, (int) $connection->fetchOne('SELECT COUNT(*) FROM towerdns_schema_migrations'), $backend);

            $bootstrap = new FreshInstallBootstrapper($connection);
            $result    = $bootstrap->bootstrap(new FreshInstallBootstrapRequest(
                '55555555-5555-4555-8555-555555555555',
                'fresh@example.test',
                password_hash('integration-password', PASSWORD_ARGON2ID),
                'Fresh administrator',
                'Fresh organization',
                'fresh-organization',
                '2026-01-01 00:00:00',
            ));
            self::assertTrue($result->createdInitialState, $backend);
            self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM accounts'), $backend);
            self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM account_memberships WHERE role = ?', ['owner']), $backend);
        });
    }

    public function testCurrentSchemaBaselinePreservesAccountsAndEncryptedProviderData(): void
    {
        $this->forEachDatabase(function (string $backend, Connection $connection): void {
            $schema = new SchemaManager($connection);
            $schema->createTablesIfNotExist();
            $schema->seedSystemRoles();
            $schema->seedSystemSettingsDefaults();

            $passwordHash = password_hash('integration-password', PASSWORD_ARGON2ID);
            $userId       = '11111111-1111-4111-8111-111111111111';
            $schema->seedFirstUser($userId, 'integration@example.test', $passwordHash, 'Integration');
            $connection->update('users', ['locale' => 'de-DE'], ['id' => $userId]);
            $accountId = (int) $connection->fetchOne('SELECT id FROM accounts WHERE personal_user_id = ?', [$userId]);
            $connection->insert('provider_accounts', [
                'account_id'            => $accountId,
                'provider_type'         => 'test',
                'name'                  => 'Encrypted fixture',
                'credentials_encrypted' => 'ciphertext-that-must-survive',
                'credentials_version'   => 1,
                'is_active'             => true,
                'created_at'            => '2026-01-01 00:00:00',
            ]);

            $manager = $this->manager($connection, $backend);
            self::assertTrue($manager->status()->canBaseline, $backend);
            $manager->migrate();

            self::assertSame($passwordHash, $connection->fetchOne('SELECT password_hash FROM users WHERE id = ?', [$userId]), $backend);
            self::assertSame('de-DE', $connection->fetchOne('SELECT locale FROM users WHERE id = ?', [$userId]), $backend);
            self::assertSame('ciphertext-that-must-survive', $connection->fetchOne('SELECT credentials_encrypted FROM provider_accounts WHERE account_id = ?', [$accountId]), $backend);
            self::assertSame(5, (int) $connection->fetchOne('SELECT COUNT(*) FROM towerdns_schema_migrations'), $backend);
        });
    }

    public function testStepUpNonceMigrationUpgradesAnAlreadyBaselinedInstallation(): void
    {
        $this->forEachDatabase(function (string $backend, Connection $connection): void {
            $schema = new SchemaManager($connection);
            $schema->createTablesIfNotExist();
            $schema->seedSystemRoles();
            $schema->seedSystemSettingsDefaults();
            $userId = '66666666-6666-4666-8666-666666666666';
            $schema->seedFirstUser($userId, 'step-up-migration@example.test', password_hash('integration-password', PASSWORD_ARGON2ID), 'Step-up migration');

            $manager = $this->manager($connection, $backend);
            $manager->migrate();
            $connection->executeStatement('DROP TABLE step_up_proof_nonces');
            $connection->delete(SchemaMigrationManager::METADATA_TABLE, ['version' => \TowerDNS\Infrastructure\Persistence\Migrations\Version20260927000100::class]);

            $status = $manager->migrate();

            self::assertTrue($status->schemaCurrent, $backend);
            self::assertSame(5, (int) $connection->fetchOne('SELECT COUNT(*) FROM towerdns_schema_migrations'), $backend);
            self::assertTrue($connection->createSchemaManager()->tablesExist(['step_up_proof_nonces']), $backend);
            self::assertSame($userId, $connection->fetchOne('SELECT id FROM users WHERE email = ?', ['step-up-migration@example.test']), $backend);
        });
    }

    public function testStepUpNonceClaimIsUniqueAndExpiredRowsCanBePruned(): void
    {
        $this->forEachDatabase(function (string $backend, Connection $connection): void {
            new SchemaMigrationManager($connection, sys_get_temp_dir() . '/towerdns-nonce-' . bin2hex(random_bytes(5)) . '.lock')->migrate();
            $nonces = new DbalStepUpProofNonceRepository($connection);
            $nonce  = str_repeat('a', 32);

            self::assertTrue($nonces->claim($nonce, time() - 1), $backend);
            self::assertTrue($nonces->claim($nonce, time() + 30), $backend);
            self::assertFalse($nonces->claim($nonce, time() + 30), $backend);
        });
    }

    public function testKnownLegacyLanguageAndAccountTypeGapsAreMigrated(): void
    {
        $this->forEachDatabase(function (string $backend, Connection $connection): void {
            $schema = new SchemaManager($connection);
            $schema->createTablesIfNotExist();
            $schema->seedSystemRoles();
            $schema->seedSystemSettingsDefaults();
            $userId = '22222222-2222-4222-8222-222222222222';
            $schema->seedFirstUser($userId, 'legacy@example.test', password_hash('integration-password', PASSWORD_ARGON2ID), 'Legacy');
            $connection->update('users', ['locale' => 'de-DE'], ['id' => $userId]);

            $connection->executeStatement('ALTER TABLE users DROP COLUMN language');
            $connection->executeStatement('ALTER TABLE accounts DROP COLUMN account_type');

            $status = $this->manager($connection, $backend)->migrate();

            self::assertSame([], $status->pending, $backend);
            self::assertSame('de-DE', $connection->fetchOne('SELECT language FROM users WHERE id = ?', [$userId]), $backend);
            self::assertSame('personal', $connection->fetchOne('SELECT account_type FROM accounts WHERE personal_user_id = ?', [$userId]), $backend);
        });
    }

    public function testLegacyWebAuthnCredentialKeyTypeIsConvertedOnServerDatabases(): void
    {
        $this->forEachDatabase(function (string $backend, Connection $connection): void {
            if ($backend === 'sqlite') {
                new SchemaManager($connection)->createTablesIfNotExist();
                self::assertSame('text', $this->credentialIdType($connection), $backend);
                return;
            }

            new SchemaManager($connection)->createTablesIfNotExist();
            if ($backend === 'mariadb') {
                // utf8mb4 primary-key indexes must remain within MariaDB's
                // 3072-byte limit. 700 characters is sufficient to model a
                // legacy text credential ID while the migration restores its
                // canonical VARBINARY(1024) representation.
                $connection->executeStatement('ALTER TABLE webauthn_credentials MODIFY credential_id VARCHAR(700) NOT NULL');
            } else {
                $connection->executeStatement('ALTER TABLE "webauthn_credentials" DROP CONSTRAINT "webauthn_credentials_pkey"');
                $connection->executeStatement("ALTER TABLE \"webauthn_credentials\" ALTER COLUMN \"credential_id\" TYPE TEXT USING convert_from(\"credential_id\", 'UTF8')");
                $connection->executeStatement('ALTER TABLE "webauthn_credentials" ADD PRIMARY KEY ("credential_id")');
            }

            $status = $this->manager($connection, $backend)->migrate();

            self::assertTrue($status->schemaCurrent, $backend);
            self::assertContains($this->credentialIdType($connection), ['binary', 'blob'], $backend);
        });
    }

    public function testForeignKeysAndUniqueConstraintsRemainEnforced(): void
    {
        $this->forEachDatabase(function (string $backend, Connection $connection): void {
            $schema = new SchemaManager($connection);
            $schema->createTablesIfNotExist();
            $schema->seedSystemRoles();
            $schema->seedSystemSettingsDefaults();
            $userId = '33333333-3333-4333-8333-333333333333';
            $schema->seedFirstUser($userId, 'constraint@example.test', password_hash('integration-password', PASSWORD_ARGON2ID), 'Constraint');

            $duplicate = static function () use ($connection): void {
                $connection->insert('users', [
                    'id'            => '44444444-4444-4444-8444-444444444444',
                    'email'         => 'constraint@example.test',
                    'password_hash' => 'hash',
                    'active'        => true,
                    'theme'         => 'system',
                    'language'      => 'en-GB',
                    'locale'        => 'en-GB',
                    'timezone'      => 'Europe/Berlin',
                    'created_at'    => '2026-01-01 00:00:00',
                    'updated_at'    => '2026-01-01 00:00:00',
                ]);
            };
            $this->assertThrowsDatabaseException($duplicate, $backend);
        });
    }

    public function testMigrationLockSerializesTwoDatabaseConnections(): void
    {
        foreach ($this->configuredBackends() as $backend) {
            $first  = $this->connect($backend);
            $second = $this->connect($backend);
            $path   = sys_get_temp_dir() . '/towerdns-lock-' . $backend . '-' . bin2hex(random_bytes(6)) . '.lock';
            $handle = new SchemaMigrationLock($first, $path)->acquire();

            try {
                $secondHandle = null;
                $blocked      = false;
                try {
                    $secondHandle = new SchemaMigrationLock($second, $path)->acquire();
                } catch (SchemaMigrationLockedException) {
                    $blocked = true;
                } finally {
                    $secondHandle?->release();
                }
                self::assertTrue($blocked, $backend);
            } finally {
                $handle->release();
                $first->close();
                $second->close();
                @unlink($path);
            }
        }
    }

    public function testIamMutationSerializationPointWorksOnConfiguredDatabases(): void
    {
        $this->forEachDatabase(function (string $backend, Connection $connection): void {
            $schema = new SchemaManager($connection);
            $schema->createTablesIfNotExist();
            $schema->seedSystemRoles();

            $repository = new DbalUserRepository($connection, new SystemClock());
            $connection->transactional(static function () use ($repository): void {
                $repository->lockSuperadminRoleForMutation();
            });

            self::assertSame('superadmin', $connection->fetchOne("SELECT id FROM roles WHERE id = 'superadmin'"), $backend);
        });
    }

    /** @param callable(string, Connection): void $scenario */
    private function forEachDatabase(callable $scenario): void
    {
        foreach ($this->configuredBackends() as $backend) {
            $connection = $this->connect($backend);
            try {
                $scenario($backend, $connection);
            } finally {
                $this->dropTestTables($connection, $backend);
                $connection->close();
            }
        }
    }

    /** @return list<string> */
    private function configuredBackends(): array
    {
        $configured = getenv('TOWERDNS_DB_INTEGRATION_DATABASES');
        if ($configured === false || trim($configured) === '') {
            return ['sqlite'];
        }

        $backends = array_values(array_unique(array_filter(array_map(
            static fn(string $backend): string => strtolower(trim($backend)),
            explode(',', $configured),
        ))));
        if (!in_array('sqlite', $backends, true)) {
            array_unshift($backends, 'sqlite');
        }

        foreach ($backends as $backend) {
            if (!in_array($backend, ['sqlite', 'mariadb', 'postgresql'], true)) {
                self::fail(sprintf('Unsupported integration database backend: %s', $backend));
            }
        }

        return $backends;
    }

    private function connect(string $backend): Connection
    {
        if ($backend === 'sqlite') {
            $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
            new SqliteConnectionConfigurator()->configure($connection);
            return $connection;
        }

        $envName = $backend === 'mariadb' ? 'TOWERDNS_TEST_MARIADB_DSN' : 'TOWERDNS_TEST_POSTGRESQL_DSN';
        $dsn     = getenv($envName);
        if ($dsn === false || trim($dsn) === '') {
            self::fail(sprintf('%s is required when %s is configured.', $envName, $backend));
        }

        $parts = parse_url($dsn);
        if (!is_array($parts) || !isset($parts['host'], $parts['path'])) {
            self::fail(sprintf('%s must be a valid database URL.', $envName));
        }

        // parse_url() guarantees string host/path/user/pass and integer port
        // components when present; the required host/path presence is checked
        // above before these values cross into DBAL.
        $host     = $parts['host'];
        $path     = $parts['path'];
        $port     = $parts['port'] ?? ($backend === 'mariadb' ? 3306 : 5432);
        $user     = $parts['user'] ?? '';
        $password = $parts['pass'] ?? '';

        $connectionParams = [
            'driver'   => $backend === 'mariadb' ? 'pdo_mysql' : 'pdo_pgsql',
            'host'     => $host,
            'port'     => $port,
            'dbname'   => ltrim($path, '/'),
            'user'     => rawurldecode($user),
            'password' => rawurldecode($password),
        ];
        if ($backend === 'mariadb') {
            $connectionParams['charset'] = 'utf8mb4';
        }

        $connection = DriverManager::getConnection($connectionParams);
        $connection->fetchOne('SELECT 1');

        return $connection;
    }

    private function manager(Connection $connection, string $backend): SchemaMigrationManager
    {
        return new SchemaMigrationManager(
            $connection,
            sys_get_temp_dir() . '/towerdns-integration-' . $backend . '-' . bin2hex(random_bytes(6)) . '.lock',
        );
    }

    private function credentialIdType(Connection $connection): string
    {
        return $connection->createSchemaManager()
            ->introspectTableByUnquotedName('webauthn_credentials')
            ->getColumn('credential_id')
            ->getTypeName();
    }

    private function dropTestTables(Connection $connection, string $backend): void
    {
        if ($backend === 'sqlite') {
            return;
        }

        $tables = array_map(
            static fn(\Doctrine\DBAL\Schema\Name\OptionallyQualifiedName $name): string => $name->getUnqualifiedName()->getValue(),
            $connection->createSchemaManager()->introspectTableNames(),
        );
        if ($backend === 'mariadb') {
            $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        }
        foreach (array_reverse($tables) as $table) {
            $identifier = $connection->getDatabasePlatform()->quoteSingleIdentifier($table);
            $suffix     = $backend === 'postgresql' ? ' CASCADE' : '';
            $connection->executeStatement('DROP TABLE IF EXISTS ' . $identifier . $suffix);
        }
        if ($backend === 'mariadb') {
            $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    private function assertThrowsDatabaseException(callable $operation, string $backend): void
    {
        try {
            $operation();
        } catch (\Throwable $exception) {
            self::assertNotSame('', $exception->getMessage(), $backend);
            return;
        }

        self::fail(sprintf('%s accepted a duplicate unique value.', $backend));
    }
}

<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use TowerDNS\Application\Services\CredentialService;
use TowerDNS\Infrastructure\Installation\FreshInstallBootstrapper;
use TowerDNS\Infrastructure\Installation\FreshInstallBootstrapRequest;
use TowerDNS\Infrastructure\Persistence\SchemaMigrationManager;
use TowerDNS\Infrastructure\Persistence\SqliteConnectionConfigurator;

/** Verifies that a database backup and its encryption-key config restore as one unit. */
final class DatabaseConfigurationRestoreIntegrationTest extends TestCase
{
    public function testBackupPairSurvivesMigrationAndRestoresAdministratorAndEncryptedCredentials(): void
    {
        if (!extension_loaded('sodium')) {
            self::markTestSkipped('The application requires ext-sodium for credential encryption.');
        }

        $root               = sys_get_temp_dir() . '/towerdns-restore-' . bin2hex(random_bytes(8));
        $installDirectory   = $root . '/install';
        $configDirectory    = $installDirectory . '/configs';
        $dataDirectory      = $installDirectory . '/data';
        $backupDirectory    = $root . '/backup';
        $databasePath       = $dataDirectory . '/towerdns.sqlite';
        $configPath         = $configDirectory . '/config.local.toml';
        $backupDatabasePath = $backupDirectory . '/towerdns.sqlite';
        $backupConfigPath   = $backupDirectory . '/config.local.toml';
        $key                = CredentialService::generateKey();
        $userId             = '88888888-8888-4888-8888-888888888888';
        $email              = 'restore@example.test';
        $password           = 'disposable-integration-password';
        $passwordHash       = password_hash($password, PASSWORD_ARGON2ID);
        $connection         = null;

        try {
            foreach ([$configDirectory, $dataDirectory, $backupDirectory] as $directory) {
                self::assertTrue(mkdir($directory, 0o700, true));
                self::assertSame(0o700, fileperms($directory) & 0o777);
            }
            self::assertFalse($this->isInsidePublicDocumentRoot($databasePath));

            $connection = $this->connect($databasePath);
            new FreshInstallBootstrapper($connection)->bootstrap(new FreshInstallBootstrapRequest(
                $userId,
                $email,
                $passwordHash,
                'Restore Test Admin',
                'Restore Test Organization',
                'restore-test-organization',
                '2026-01-01 00:00:00',
            ));
            $connection->update('users', ['locale' => 'de-DE'], ['id' => $userId]);

            $organizationId = (int) $connection->fetchOne(
                'SELECT id FROM accounts WHERE account_type = ? AND slug = ?',
                ['organization', 'restore-test-organization'],
            );
            self::assertGreaterThan(0, $organizationId);

            $credentials = ['api_token' => 'disposable-credential-for-restore-test'];
            $plaintext   = json_encode($credentials, JSON_THROW_ON_ERROR);
            $encryptor   = new CredentialService($key);
            $ciphertext  = $encryptor->encrypt($plaintext);
            $connection->insert('provider_accounts', [
                'account_id'            => $organizationId,
                'provider_type'         => 'test-provider',
                'name'                  => 'Disposable restore fixture',
                'credentials_encrypted' => $ciphertext,
                'credentials_version'   => CredentialService::currentVersion(),
                'is_active'             => true,
                'created_at'            => '2026-01-01 00:00:00',
            ]);
            $providerAccountId = (int) $connection->lastInsertId();
            $connection->insert('managed_zones', [
                'account_id'          => $organizationId,
                'provider_account_id' => $providerAccountId,
                'provider_zone_id'    => 'disposable-zone-1',
                'canonical_name'      => 'restore-example.test',
                'created_at'          => '2026-01-01 00:00:00',
            ]);

            $config = $this->configuration($databasePath, $key);
            self::assertSame(strlen($config), file_put_contents($configPath, $config, LOCK_EX));
            self::assertTrue(chmod($configPath, 0o600));

            // The test snapshots the closed SQLite database together with the exact
            // bootstrap config whose key decrypts the stored provider credential.
            $connection->close();
            $connection = null;
            self::assertTrue(copy($databasePath, $backupDatabasePath));
            self::assertTrue(copy($configPath, $backupConfigPath));

            // Recreate a known pre-account-type schema state, then exercise the
            // same explicit migration path used when application code is updated.
            $connection = $this->connect($databasePath);
            $connection->executeStatement('ALTER TABLE users DROP COLUMN language');
            $connection->executeStatement('ALTER TABLE accounts DROP COLUMN account_type');
            $connection->executeStatement('DROP TABLE towerdns_schema_migrations');
            $migration = new SchemaMigrationManager($connection, $root . '/install/schema-migration.lock');
            $status    = $migration->migrate();
            self::assertTrue($status->schemaCurrent);
            self::assertSame([], $status->pending);
            $this->assertInstalledData($connection, $userId, $email, $password, $passwordHash, $organizationId, $ciphertext, $key);
            self::assertSame('de-DE', $connection->fetchOne('SELECT language FROM users WHERE id = ?', [$userId]));
            $connection->close();
            $connection = null;

            // Simulate a changed/lost local key, then restore the paired database
            // and configuration backups in place.
            $changedKey       = CredentialService::generateKey();
            $wrongKeyRejected = false;
            try {
                $wrongPlaintext = new CredentialService($changedKey)->decrypt($ciphertext);
                new CredentialService($changedKey)->wipe($wrongPlaintext);
            } catch (\RuntimeException) {
                $wrongKeyRejected = true;
            }
            self::assertTrue($wrongKeyRejected, 'A mismatched encryption key must not decrypt restored credentials.');
            self::assertSame(strlen($this->configuration($databasePath, $changedKey)), file_put_contents(
                $configPath,
                $this->configuration($databasePath, $changedKey),
                LOCK_EX,
            ));
            self::assertTrue(copy($backupDatabasePath, $databasePath));
            self::assertTrue(copy($backupConfigPath, $configPath));

            $restoredConfig = file_get_contents($configPath);
            self::assertIsString($restoredConfig);
            self::assertStringContainsString('encryption_key = "' . $key . '"', $restoredConfig);
            self::assertStringContainsString('path = "' . $databasePath . '"', $restoredConfig);
            self::assertSame(0o600, fileperms($configPath) & 0o777);

            $connection = $this->connect($databasePath);
            self::assertTrue(new SchemaMigrationManager($connection, $root . '/install/restore-validation.lock')->status()->schemaCurrent);
            $this->assertInstalledData($connection, $userId, $email, $password, $passwordHash, $organizationId, $ciphertext, $key);
            self::assertSame('restore-example.test', $connection->fetchOne(
                'SELECT canonical_name FROM managed_zones WHERE provider_zone_id = ?',
                ['disposable-zone-1'],
            ));
        } finally {
            $connection?->close();
            foreach ([
                $databasePath,
                $databasePath . '-journal',
                $databasePath . '-wal',
                $databasePath . '-shm',
                $configPath,
                $backupDatabasePath,
                $backupConfigPath,
                $root . '/install/schema-migration.lock',
                $root . '/install/restore-validation.lock',
            ] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            foreach ([$configDirectory, $dataDirectory, $installDirectory, $backupDirectory, $root] as $directory) {
                if (is_dir($directory)) {
                    rmdir($directory);
                }
            }
        }
    }

    private function connect(string $databasePath): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $databasePath]);
        new SqliteConnectionConfigurator()->configure($connection);
        return $connection;
    }

    private function configuration(string $databasePath, string $key): string
    {
        return "[database]\ndriver = \"pdo_sqlite\"\n\n"
            . "[database.sqlite]\npath = \"{$databasePath}\"\n\n"
            . "[security]\nencryption_key = \"{$key}\"\n";
    }

    private function isInsidePublicDocumentRoot(string $path): bool
    {
        $projectRoot = dirname(__DIR__, 2);
        $publicRoot  = realpath($projectRoot . '/httpdocs');

        return $publicRoot !== false && str_starts_with(realpath(dirname($path)) ?: dirname($path), $publicRoot . DIRECTORY_SEPARATOR);
    }

    private function assertInstalledData(
        Connection $connection,
        string $userId,
        string $email,
        string $password,
        string $passwordHash,
        int $organizationId,
        string $ciphertext,
        string $key,
    ): void {
        $user = $connection->fetchAssociative('SELECT email, password_hash FROM users WHERE id = ?', [$userId]);
        self::assertIsArray($user);
        self::assertSame($email, $user['email']);
        self::assertSame($passwordHash, $user['password_hash']);
        self::assertTrue(password_verify($password, (string) $user['password_hash']));

        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM accounts'));
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM account_memberships WHERE user_id = ? AND role = ?', [$userId, 'owner']));
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM account_resource_limits WHERE account_id IN (SELECT id FROM accounts)'));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM provider_accounts WHERE account_id = ?', [$organizationId]));
        self::assertSame($ciphertext, $connection->fetchOne('SELECT credentials_encrypted FROM provider_accounts WHERE account_id = ?', [$organizationId]));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM managed_zones WHERE account_id = ?', [$organizationId]));

        $plaintext = new CredentialService($key)->decrypt($ciphertext);
        try {
            self::assertSame(['api_token' => 'disposable-credential-for-restore-test'], json_decode($plaintext, true, flags: JSON_THROW_ON_ERROR));
        } finally {
            new CredentialService($key)->wipe($plaintext);
        }
    }
}

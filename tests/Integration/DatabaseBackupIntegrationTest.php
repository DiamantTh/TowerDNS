<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use TowerDNS\Application\Services\CredentialService;
use TowerDNS\Infrastructure\Backup\BackupException;
use TowerDNS\Infrastructure\Backup\DatabaseBackup;
use TowerDNS\Infrastructure\Console\DatabaseBackupCommand;
use TowerDNS\Infrastructure\Installation\FreshInstallBootstrapper;
use TowerDNS\Infrastructure\Installation\FreshInstallBootstrapRequest;
use TowerDNS\Infrastructure\Persistence\DbalApiKeyRepository;
use TowerDNS\Infrastructure\Persistence\SchemaMigrationManager;

/** @psalm-api Discovered by PHPUnit. */
final class DatabaseBackupIntegrationTest extends TestCase
{
    private const string USER = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const string NOW  = '2026-11-24 12:00:00';

    public function testSqliteWalSnapshotAndOfflineRestorePreserveApplicationData(): void
    {
        $this->withFixture(function (string $root, string $key, DatabaseBackup $backup): void {
            $source = $this->sqlite($root . '/source.db');
            $this->seed($source, $key);
            $source->fetchOne('PRAGMA journal_mode=WAL');
            $writer = $this->sqlite($root . '/source.db');
            $writer->beginTransaction();
            $writer->update('users', ['locale' => 'de-DE'], ['id' => self::USER]);
            try {
                $manifest = $backup->create($source, $root . '/bundle');
            } finally {
                $writer->commit();
                $writer->close();
                $source->close();
            }
            self::assertSame('sqlite', $manifest['database_type']);
            self::assertSame('none', $manifest['encryption']);
            self::assertSame(0o700, (int) fileperms($root . '/bundle') & 0o777);
            foreach (['manifest.json', 'database.dump'] as $file) {
                self::assertSame(0o600, (int) fileperms($root . '/bundle/' . $file) & 0o777);
            }
            self::assertSame($manifest, $backup->verify($root . '/bundle'));
            touch($root . '/conflict.db-wal');
            $this->mustReject(fn(): array => $backup->restore($root . '/bundle', $root . '/conflict.db', null, 'offline-restore'), 'sidecars');
            self::assertFileDoesNotExist($root . '/conflict.db');
            $this->mustReject(fn(): array => $backup->restore($root . '/bundle', $root . '/unused.db', null, ''), 'requires --confirm');
            $this->mustReject(fn(): array => new DatabaseBackup($root . '/checkout', null, 'test')->restore($root . '/bundle', $root . '/unused.db', null, 'offline-restore'), 'application key');
            $this->mustReject(fn(): array => new DatabaseBackup($root . '/checkout', CredentialService::generateKey(), 'test')->restore($root . '/bundle', $root . '/unused.db', null, 'offline-restore'), 'does not match');
            self::assertFileDoesNotExist($root . '/unused.db');

            $restore = new CommandTester(new DatabaseBackupCommand($backup, static function (): Connection {
                throw new \LogicException('SQLite restore must not open the configured database.');
            }, 'restore'));
            $arguments = ['directory' => $root . '/bundle', '--sqlite-target' => $root . '/restored.db'];
            self::assertSame(2, $restore->execute($arguments));
            self::assertSame(0, $restore->execute([...$arguments, '--confirm' => 'offline-restore']));
            self::assertStringContainsString('offline_operator_review_required', $restore->getDisplay());
            self::assertSame(0o600, (int) fileperms($root . '/restored.db') & 0o777);
            $restored = $this->sqlite($root . '/restored.db');
            $this->assertContents($restored, $key);
            self::assertSame('en-GB', $restored->fetchOne('SELECT locale FROM users'));
            $this->mustReject(fn(): array => $backup->restore($root . '/bundle', $root . '/restored.db', null, 'offline-restore'), 'new target');
            $restored->close();
        });
    }

    public function testVerificationRejectsDamagedAndUnsupportedBundles(): void
    {
        $this->withFixture(function (string $root, string $key, DatabaseBackup $backup): void {
            $source = $this->sqlite($root . '/source.db');
            $this->seed($source, $key);
            $command = new CommandTester(new DatabaseBackupCommand($backup, static fn(): Connection => $source, 'create'));
            self::assertSame(2, $command->execute(['directory' => $root . '/bundle']));
            self::assertFileDoesNotExist($root . '/bundle');
            self::assertSame(0, $command->execute(['directory' => $root . '/bundle', '--allow-plaintext' => true]));
            self::assertStringNotContainsString($key, $command->getDisplay());
            $path                = $root . '/bundle/manifest.json';
            $valid               = (string) file_get_contents($path);
            $manifest            = json_decode($valid, true, flags: JSON_THROW_ON_ERROR);
            $manifest['version'] = 0;
            file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR));
            $this->mustReject(fn(): array => $backup->verify($root . '/bundle'), 'Unsupported');
            $manifest['version']       = 1;
            $manifest['database_type'] = 'postgresql';
            file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR));
            $this->mustReject(fn(): array => $backup->restore($root . '/bundle', $root . '/wrong.db', null, 'offline-restore'), 'does not match');
            file_put_contents($path, $valid);
            chmod($path, 0o644);
            $this->mustReject(fn(): array => $backup->verify($root . '/bundle'), 'mode-0600');
            chmod($path, 0o600);
            file_put_contents($root . '/bundle/database.dump', 'truncated', FILE_APPEND);
            $this->mustReject(fn(): array => $backup->verify($root . '/bundle'), 'SHA-256');
            self::assertFileDoesNotExist($root . '/wrong.db');

            $invalid = $this->sqlite($root . '/uninitialized.db');
            $invalid->executeStatement('CREATE TABLE unrelated (id INT)');
            $this->mustReject(fn(): array => $backup->create($invalid, $root . '/failed'), 'schema');
            self::assertFileDoesNotExist($root . '/failed');
            $invalid->close();
            $source->close();
        });
    }

    public function testPreviousMonitoringSchemaCanBeRestoredThenMigrated(): void
    {
        $this->withFixture(function (string $root, string $key, DatabaseBackup $backup): void {
            $source = $this->sqlite($root . '/source.db');
            $this->seed($source, $key);
            $source->executeStatement('DROP TABLE dns_monitoring_deliveries');
            $source->executeStatement('DROP TABLE dns_monitoring_alerts');
            $source->delete(SchemaMigrationManager::METADATA_TABLE, ['version' => \TowerDNS\Infrastructure\Persistence\Migrations\Version20261124000100::class]);
            $backup->create($source, $root . '/bundle');
            $backup->restore($root . '/bundle', $root . '/restored.db', null, 'offline-restore');
            $restored = $this->sqlite($root . '/restored.db');
            $manager  = new SchemaMigrationManager($restored, $root . '/upgrade.lock');
            self::assertCount(1, $manager->status()->pending);
            self::assertSame([], $manager->migrate()->pending);
            self::assertSame([], $manager->migrate()->pending);
            $this->assertContents($restored, $key);
            $restored->close();
            $source->close();
        });
    }

    /** @return array<string,array{string}> */
    public static function nativeDrivers(): array
    {
        return ['MariaDB' => ['mariadb'], 'PostgreSQL' => ['postgresql']];
    }

    #[DataProvider('nativeDrivers')]
    public function testNativeServerRoundTrip(string $driver): void
    {
        $prefix    = 'TOWERDNS_BACKUP_' . strtoupper($driver);
        $sourceDsn = getenv($prefix . '_SOURCE_DSN');
        $targetDsn = getenv($prefix . '_TARGET_DSN');
        if ($sourceDsn === false || $targetDsn === false || getenv('TOWERDNS_BACKUP_TEST_CONFIRM') !== 'isolated-empty-databases') {
            self::markTestSkipped('Requires explicitly approved empty source and restore test databases.');
        }
        $parser       = new DsnParser(['mysql' => 'pdo_mysql', 'pgsql' => 'pdo_pgsql']);
        $sourceParams = $parser->parse($sourceDsn);
        $targetParams = $parser->parse($targetDsn);
        self::assertSame($driver === 'mariadb' ? 'pdo_mysql' : 'pdo_pgsql', $sourceParams['driver'] ?? null);
        self::assertSame($sourceParams['driver'] ?? null, $targetParams['driver'] ?? null);
        self::assertMatchesRegularExpression('/\Atowerdns_backup_[a-z0-9_]+_test\z/D', $sourceParams['dbname'] ?? '');
        self::assertMatchesRegularExpression('/\Atowerdns_restore_[a-z0-9_]+_test\z/D', $targetParams['dbname'] ?? '');

        $this->withFixture(function (string $root, string $key, DatabaseBackup $backup) use ($sourceParams, $targetParams, $driver): void {
            $source = DriverManager::getConnection($sourceParams);
            $target = DriverManager::getConnection($targetParams);
            try {
                self::assertSame([], $source->createSchemaManager()->introspectTableNames());
                self::assertSame([], $target->createSchemaManager()->introspectTableNames());
                $this->seed($source, $key);
                self::assertSame($driver, $backup->create($source, $root . '/bundle')['database_type']);
                $backup->verify($root . '/bundle');
                $backup->restore($root . '/bundle', null, $target, 'offline-restore');
                $this->assertContents($target, $key);
                $this->mustReject(fn(): array => $backup->restore($root . '/bundle', null, $target, 'offline-restore'), 'not empty');
            } finally {
                $source->close();
                $target->close();
            }
        });
    }

    /** @param callable(string,string,DatabaseBackup): void $test */
    private function withFixture(callable $test): void
    {
        $root = sys_get_temp_dir() . '/towerdns-backup-test-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root . '/checkout/data', 0o700, true));
        $key = CredentialService::generateKey();
        try {
            $test($root, $key, new DatabaseBackup($root . '/checkout', $key, 'test'));
        } finally {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($root);
        }
    }

    private function sqlite(string $path): Connection
    {
        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]);
    }

    private function seed(Connection $db, string $key): void
    {
        new FreshInstallBootstrapper($db)->bootstrap(new FreshInstallBootstrapRequest(
            self::USER,
            'restore@example.test',
            password_hash('disposable-password', PASSWORD_ARGON2ID),
            'Backup test',
            'Backup organization',
            'backup-organization',
            self::NOW,
        ));
        $db->executeStatement(
            'UPDATE users SET webauthn_user_handle = ?, webauthn_user_handle_rp_id = ? WHERE id = ?',
            [str_repeat("\0\xff", 32), 'example.test', self::USER],
            [ParameterType::BINARY, ParameterType::STRING, ParameterType::STRING]
        );
        $account = (int) $db->fetchOne("SELECT id FROM accounts WHERE slug = 'backup-organization'");
        $cipher  = new CredentialService($key);
        $db->insert('totp_credentials', ['id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'user_id' => self::USER,
            'secret_encrypted'                => $cipher->encrypt('fixture-totp'), 'label' => 'Backup TOTP', 'created_at' => self::NOW]);
        $db->insert('provider_accounts', ['account_id' => $account, 'provider_type' => 'fixture', 'name' => 'Restore',
            'credentials_encrypted'                    => $cipher->encrypt('fixture-provider'), 'created_at' => self::NOW]);
        $provider = (int) $db->fetchOne('SELECT id FROM provider_accounts');
        $db->insert('managed_zones', ['account_id' => $account, 'provider_account_id' => $provider, 'provider_zone_id' => 'fixture',
            'canonical_name'                       => 'example.test', 'created_at' => self::NOW]);
        $zone = (int) $db->fetchOne('SELECT id FROM managed_zones');
        $db->insert('dns_monitoring_config', ['managed_zone_id' => $zone, 'enabled' => true, 'interval_minutes' => 30, 'updated_at' => self::NOW]);
        $keys   = new DbalApiKeyRepository($db);
        $expiry = new \DateTimeImmutable('2026-12-01T00:00:00Z');
        $keys->create(self::USER, 'Fixture', hash('sha256', 'fixture'), 'tlsa:append', $account, $zone, '_443._tcp', $expiry);
        $keys->create(self::USER, 'Revoked fixture', hash('sha256', 'revoked'), 'tlsa:read', $account, $zone, null, $expiry);
        $keys->revoke((int) $db->fetchOne('SELECT MAX(id) FROM api_keys'), self::USER);
    }

    private function assertContents(Connection $db, string $key): void
    {
        $handle = $db->fetchOne('SELECT webauthn_user_handle FROM users');
        self::assertSame(str_repeat("\0\xff", 32), is_resource($handle) ? stream_get_contents($handle) : $handle);
        self::assertTrue(password_verify('disposable-password', (string) $db->fetchOne('SELECT password_hash FROM users')));
        self::assertSame(2, (int) $db->fetchOne('SELECT COUNT(*) FROM account_memberships'));
        self::assertSame(30, (int) $db->fetchOne('SELECT interval_minutes FROM dns_monitoring_config'));
        self::assertSame('example.test', $db->fetchOne('SELECT canonical_name FROM managed_zones'));
        $cipher = new CredentialService($key);
        self::assertSame('fixture-totp', $cipher->decrypt((string) $db->fetchOne('SELECT secret_encrypted FROM totp_credentials')));
        self::assertSame('fixture-provider', $cipher->decrypt((string) $db->fetchOne('SELECT credentials_encrypted FROM provider_accounts')));
        $keys  = new DbalApiKeyRepository($db);
        $when  = new \DateTimeImmutable('2026-11-25T12:00:00Z');
        $grant = $keys->findGrant(hash('sha256', 'fixture'), $when);
        self::assertInstanceOf(\TowerDNS\Domain\Auth\ApiKeyGrant::class, $grant);
        self::assertSame('tlsa:append', $grant->scope);
        self::assertSame('_443._tcp', $grant->owner);
        self::assertNull($keys->findGrant(hash('sha256', 'revoked'), $when));
        self::assertNull($keys->findGrant(hash('sha256', 'fixture'), new \DateTimeImmutable('2026-12-01T00:00:00Z')));
    }

    /** @param callable(): mixed $operation */
    private function mustReject(callable $operation, string $message): void
    {
        try {
            $operation();
            self::fail('Expected the backup operation to be refused.');
        } catch (BackupException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }
    }
}

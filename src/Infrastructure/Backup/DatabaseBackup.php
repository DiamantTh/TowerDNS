<?php

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Backup;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use TowerDNS\Infrastructure\Persistence\SchemaMigrationLock;
use TowerDNS\Infrastructure\Persistence\SchemaMigrationManager;

/** @psalm-api Constructed for the CLI. */
final readonly class DatabaseBackup
{
    private const string PAYLOAD     = 'database.dump';
    private const string MANIFEST    = 'manifest.json';
    private const string RESTORE_ACK = 'offline-restore';

    public function __construct(private string $projectRoot, private ?string $encodedKey, private string $applicationVersion) {}

    /** @return array<string, mixed> */
    public function create(Connection $source, string $target, ?string $passphrase = null): array
    {
        $keyId = $this->keyId();
        $type  = $this->type($source);
        if ($type === 'sqlite') {
            /** @psalm-suppress InternalMethod Inspect the configured SQLite path before DBAL can open or create the file. */
            $sourcePath = $source->getParams()['path'] ?? null;
            if (!is_string($sourcePath) || !is_file($sourcePath)) {
                throw new BackupException('SQLite backup requires an existing file-backed database.');
            }
        }
        $target = $this->newPath($target);
        if (!@mkdir($target, 0o700)) {
            throw new BackupException('Backup directory already exists or cannot be created.');
        }
        $path     = $target . '/' . self::PAYLOAD;
        $staging  = null;
        $complete = false;
        try {
            $staging  = $this->createScratch(sys_get_temp_dir(), 'towerdns-backup-stage-');
            $snapshot = $staging . '/' . self::PAYLOAD;
            $lock     = new SchemaMigrationLock($source, $this->projectRoot . '/data/schema-migrations.lock')->acquire();
            try {
                $migrations      = $this->migrationVersions($source);
                $databaseVersion = $this->databaseVersion($source, $type);
                if ($type === 'sqlite') {
                    $source->executeStatement('VACUUM INTO ?', [$snapshot]);
                    $tool = 'SQLite VACUUM INTO';
                } else {
                    $tool = new NativeBackupTools($staging)->dump($source, $type, $snapshot);
                }
            } finally {
                $lock->release();
            }
            if (!chmod($snapshot, 0o600)) {
                throw new BackupException('Unable to protect the backup payload.');
            }
            $this->inspect($staging, $type, $migrations);
            $encryption = 'none';
            $pending    = $path . '.pending';
            if ($passphrase !== null) {
                BackupPayloadCipher::encrypt($snapshot, $pending, $passphrase);
                $encryption = [
                    'algorithm' => 'secretstream_xchacha20poly1305',
                    'kdf'       => 'argon2id13',
                    'format'    => 'towerdns/backup-payload-encrypted-v1',
                ];
            } elseif (!copy($snapshot, $pending) || !chmod($pending, 0o600)) {
                throw new BackupException('Unable to publish a protected plaintext payload.');
            }
            if (!rename($pending, $path)) {
                @unlink($pending);
                throw new BackupException('Unable to publish the backup payload atomically.');
            }
            $hash  = hash_file('sha256', $path);
            $bytes = filesize($path);
            if ($hash === false || $bytes === false || $bytes < 16) {
                throw new BackupException('Backup payload is incomplete.');
            }

            $manifest = [
                'format'                 => 'towerdns/database-backup',
                'version'                => 1,
                'created_at'             => gmdate(DATE_ATOM),
                'application_version'    => $this->applicationVersion,
                'database_type'          => $type,
                'database_version'       => $databaseVersion,
                'tool'                   => $tool,
                'migrations'             => $migrations,
                'components'             => ['application_database'],
                'external_requirements'  => ['original_application_key', 'installation_configuration', 'compatible_application_release'],
                'encryption'             => $encryption,
                'application_key_sha256' => $keyId,
                'bytes'                  => $bytes,
                'sha256'                 => $hash,
            ];
            $json    = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            $pending = $target . '/manifest.pending';
            if (file_put_contents($pending, $json) !== strlen($json) || !chmod($pending, 0o600) || !rename($pending, $target . '/' . self::MANIFEST)) {
                throw new BackupException('Unable to publish the backup manifest.');
            }
            $complete = true;
            return $manifest;
        } finally {
            if ($staging !== null) {
                $this->removeScratch($staging, [self::PAYLOAD, self::PAYLOAD . '-journal']);
            }
            if (!$complete) {
                $this->removeScratch($target, [self::PAYLOAD, self::PAYLOAD . '.pending', 'manifest.pending', self::PAYLOAD . '-journal']);
            }
        }
    }

    /** @return array<string, mixed> */
    public function verify(string $directory, ?string $passphrase = null): array
    {
        $directory = $this->privateDirectory($directory);
        $manifest  = $this->readManifest($directory);
        if ($this->encrypted($manifest)) {
            if ($passphrase === null) {
                throw new BackupException('This backup is encrypted. Supply its passphrase interactively to verify authentication and database format.');
            }
            $scratch = $this->createScratch(sys_get_temp_dir());
            try {
                BackupPayloadCipher::decrypt($directory . '/' . self::PAYLOAD, $scratch . '/' . self::PAYLOAD, $passphrase);
                $this->inspect($scratch, (string) $manifest['database_type'], $this->manifestVersions($manifest));
            } finally {
                $this->removeScratch($scratch, [self::PAYLOAD]);
            }
            $manifest['verification'] = ['ciphertext_integrity' => true, 'authentication' => 'authenticated', 'database_format' => 'valid'];
        } else {
            $this->inspect($directory, (string) $manifest['database_type'], $this->manifestVersions($manifest));
            $manifest['verification'] = ['ciphertext_integrity' => null, 'authentication' => 'not_encrypted', 'database_format' => 'valid'];
        }
        return $manifest;
    }

    public function isEncryptedBackup(string $directory): bool
    {
        return $this->encrypted($this->readManifest($this->privateDirectory($directory)));
    }

    public function readPassphraseFile(string $path): string
    {
        $resolved = realpath($path);
        $root     = realpath($this->projectRoot);
        $mode     = is_file($path) ? fileperms($path) : false;
        $bytes    = is_file($path) ? filesize($path) : false;
        if (is_link($path) || $resolved === false || $root === false || !is_file($resolved)
                           || str_starts_with($resolved, $root . '/') || ($mode === false) || ($mode & 0o077) !== 0
                           || ($bytes === false) || $bytes < 16 || $bytes > 4096) {
            throw new BackupException('Passphrase file must be a private regular file outside the TowerDNS checkout, 16 to 4096 bytes, with no group or world permissions.');
        }
        $passphrase = file_get_contents($resolved);
        if (!is_string($passphrase)) {
            throw new BackupException('Unable to read the protected backup passphrase file.');
        }
        if (str_ends_with($passphrase, "\r\n")) {
            $passphrase = substr($passphrase, 0, -2);
        } elseif (str_ends_with($passphrase, "\n")) {
            $passphrase = substr($passphrase, 0, -1);
        }
        if (strlen($passphrase) < 16 || str_contains($passphrase, "\n") || str_contains($passphrase, "\r")) {
            throw new BackupException('Passphrase file must contain one 16 to 4096 byte line.');
        }
        return $passphrase;
    }

    /** @return array<string,mixed> */
    public function restore(string $directory, ?string $sqliteFile, ?Connection $server, string $confirmation, ?string $passphrase = null): array
    {
        if ($confirmation !== self::RESTORE_ACK) {
            throw new BackupException('Restore requires --confirm=offline-restore. Keep the target offline until access and pending notifications have been reviewed.');
        }
        $directory = $this->privateDirectory($directory);
        $manifest  = $this->readManifest($directory);
        if (!hash_equals((string) $manifest['application_key_sha256'], $this->keyId())) {
            throw new BackupException('The configured application key does not match this backup.');
        }
        $type     = (string) $manifest['database_type'];
        $versions = $this->manifestVersions($manifest);
        if ($sqliteFile !== null) {
            if ($type !== 'sqlite' || $server instanceof Connection) {
                throw new BackupException('The backup does not match the SQLite restore target.');
            }
            $target  = $this->newPath($sqliteFile);
            $scratch = $this->createScratch(dirname($target));
            try {
                $this->copyCheckedPayload($directory, $scratch, $manifest, $passphrase);
                $this->inspect($scratch, 'sqlite', $versions);
                if (file_exists($target . '-wal') || file_exists($target . '-shm') || file_exists($target . '-journal')
                                                  || is_link($target . '-wal') || is_link($target . '-shm') || is_link($target . '-journal')
                                                  || !@link($scratch . '/' . self::PAYLOAD, $target)) {
                    throw new BackupException('SQLite restore requires a new target file with no existing database or sidecars.');
                }
            } finally {
                $this->removeScratch($scratch, [self::PAYLOAD, self::PAYLOAD . '-journal']);
            }
        } else {
            if (!$server instanceof Connection || $type === 'sqlite' || $type !== $this->type($server)) {
                throw new BackupException('The backup does not match the server restore target.');
            }
            $name = $server->getDatabase();
            if (!is_string($name) || preg_match('/\Atowerdns_restore_[a-z0-9_]{1,40}\z/D', $name) !== 1) {
                throw new BackupException('Configure an isolated server database named towerdns_restore_<name>.');
            }
            /** @psalm-suppress InternalMethod Use only non-secret target identity fields to avoid reusing a failed restore destination. */
            $params   = $server->getParams();
            $host     = $params['host'] ?? 'localhost';
            $port     = $params['port'] ?? '';
            $identity = implode("\0", [
                $type,
                $name,
                $host,
                (string) $port,
            ]);
            $targetGuard = new RestoreTargetGuard($this->projectRoot . '/data/restore-tainted');
            $targetGuard->assertClean($identity);
            $scratch = $this->createScratch(sys_get_temp_dir());
            try {
                $this->copyCheckedPayload($directory, $scratch, $manifest, $passphrase);
                $this->inspect($scratch, $type, $versions);
                $lock = new SchemaMigrationLock($server)->acquire();
                try {
                    $this->assertEmptyServer($server, $type);
                    try {
                        new NativeBackupTools($scratch)->restore($server, $type, $scratch . '/' . self::PAYLOAD);
                        $this->assertRestored($server, $versions);
                    } catch (BackupException $error) {
                        $targetGuard->markTainted($identity);
                        throw new BackupException($error->getMessage() . ' Keep the target offline and verify its state before retrying.', $error->getCode(), $error);
                    } catch (\Throwable) {
                        $targetGuard->markTainted($identity);
                        throw new BackupException('Server restore or post-restore checks failed. Keep the target offline. Inspect or recreate the isolated database before retrying.');
                    }
                } finally {
                    $lock->release();
                }
            } finally {
                $this->removeScratch($scratch, [self::PAYLOAD]);
            }
        }

        return ['restored' => true, 'database_type' => $type, 'migrations' => $versions, 'activation' => 'offline_operator_review_required'];
    }

    private function keyId(): string
    {
        $key = $this->encodedKey === null ? false : base64_decode($this->encodedKey, true);
        if ($key === false || strlen($key) !== 32) {
            throw new BackupException('Configure the original 32-byte TowerDNS application key before backup or restore.');
        }
        $digest = hash('sha256', $key);
        if (function_exists('sodium_memzero')) {
            sodium_memzero($key);
        }
        return $digest;
    }

    private function type(Connection $connection): string
    {
        if ($connection->getDatabasePlatform() instanceof PostgreSQLPlatform && $connection->fetchOne('SELECT current_schema()') !== 'public') {
            throw new BackupException('PostgreSQL backup and restore require the TowerDNS public schema.');
        }
        return match (true) {
            $connection->getDatabasePlatform() instanceof SQLitePlatform     => 'sqlite',
            $connection->getDatabasePlatform() instanceof MariaDBPlatform    => 'mariadb',
            $connection->getDatabasePlatform() instanceof PostgreSQLPlatform => 'postgresql',
            default                                                          => throw new BackupException('Unsupported database type.'),
        };
    }

    private function databaseVersion(Connection $connection, string $type): string
    {
        return (string) $connection->fetchOne($type === 'sqlite' ? 'SELECT sqlite_version()' : 'SELECT VERSION()');
    }

    /** @return list<string> */
    private function migrationVersions(Connection $connection): array
    {
        $status = new SchemaMigrationManager($connection)->status();
        if (!$connection->createSchemaManager()->tablesExist(['users', 'roles', 'role_permissions', 'system_settings'])
            || !$status->metadataInitialized || $status->executed === [] || $status->dataIssues !== []
            || ($status->schemaIssues !== [] && ($status->pending === [] || !array_all($status->schemaIssues, static fn(string $issue): bool => preg_match('/\Amissing (?:table|column|index|foreign key)\b/', $issue) === 1)))) {
            throw new BackupException('TowerDNS schema is unversioned, incompatible or inconsistent.');
        }
        $known = array_column([...$status->executed, ...$status->pending], 'version');
        sort($known, SORT_STRING);
        $executed = array_column($status->executed, 'version');
        if ($executed !== array_slice($known, 0, count($executed))) {
            throw new BackupException('Migration history is not an uninterrupted supported prefix.');
        }
        return $executed;
    }

    /** @param list<string> $versions */
    private function assertRestored(Connection $connection, array $versions): void
    {
        if ($this->migrationVersions($connection) !== $versions) {
            throw new BackupException('The restored database migration history differs from the manifest.');
        }
    }

    /** @param list<string> $versions */
    private function inspect(string $directory, string $type, array $versions): void
    {
        $path = $directory . '/' . self::PAYLOAD;
        if ($type === 'sqlite') {
            if (file_get_contents($path, length: 16) !== "SQLite format 3\0") {
                throw new BackupException('Invalid SQLite snapshot header.');
            }
            $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]);
            try {
                $db->executeStatement('PRAGMA query_only = ON');
                $db->executeStatement('PRAGMA trusted_schema = OFF');
                if ($db->fetchOne('PRAGMA integrity_check(1)') !== 'ok' || $db->fetchOne('SELECT 1 FROM pragma_foreign_key_check LIMIT 1') !== false) {
                    throw new BackupException('SQLite integrity or foreign key check failed.');
                }
                $this->assertRestored($db, $versions);
            } finally {
                $db->close();
            }
        } elseif ($type === 'postgresql') {
            if (file_get_contents($path, length: 5) !== 'PGDMP') {
                throw new BackupException('Invalid PostgreSQL custom archive header.');
            }
            new NativeBackupTools($directory)->inspectPostgres($path);
        } elseif (!str_contains((string) file_get_contents($path, length: 1024), 'MariaDB dump')) {
            throw new BackupException('Invalid MariaDB dump header.');
        }
    }

    /** @return array<string,mixed> */
    private function readManifest(string $directory): array
    {
        foreach ([self::MANIFEST, self::PAYLOAD] as $file) {
            $path = $directory . '/' . $file;
            if (is_link($path) || !is_file($path) || ((int) fileperms($path) & 0o777) !== 0o600) {
                throw new BackupException('Backup requires regular mode-0600 manifest and payload files.');
            }
        }
        $manifest = json_decode((string) file_get_contents($directory . '/' . self::MANIFEST, length: 16385), true, 16);
        if (!is_array($manifest) || ($manifest['format'] ?? null) !== 'towerdns/database-backup' || ($manifest['version'] ?? null) !== 1
                                 || !in_array($manifest['database_type'] ?? null, ['sqlite', 'mariadb', 'postgresql'], true)
                                 || ($manifest['components'] ?? null) !== ['application_database'] || !$this->validEncryption($manifest['encryption'] ?? null)
                                 || !is_string($manifest['database_version'] ?? null) || !is_string($manifest['application_version'] ?? null)
                                 || !is_string($manifest['tool'] ?? null) || !is_string($manifest['created_at'] ?? null)
                                 || !is_string($manifest['application_key_sha256'] ?? null) || preg_match('/\A[0-9a-f]{64}\z/D', $manifest['application_key_sha256']) !== 1
                                 || !is_string($manifest['sha256'] ?? null) || preg_match('/\A[0-9a-f]{64}\z/D', $manifest['sha256'])                                 !== 1 || !is_int($manifest['bytes'] ?? null) || $manifest['bytes'] < 16) {
            throw new BackupException('Unsupported or incomplete backup manifest.');
        }
        $this->manifestVersions($manifest);
        $this->checkPayload($directory, $manifest);
        return $manifest;
    }

    /**
     * @param array<string,mixed> $manifest
     * @return list<string>
     */
    private function manifestVersions(array $manifest): array
    {
        $versions = $manifest['migrations'] ?? null;
        $known    = SchemaMigrationManager::supportedVersions();
        if (!is_array($versions) || $versions === [] || $versions !== array_slice($known, 0, count($versions))) {
            throw new BackupException('Backup migration versions are unsupported or newer than this code.');
        }
        return array_slice($known, 0, count($versions));
    }

    /** @param array<string,mixed> $manifest */
    private function checkPayload(string $directory, array $manifest): void
    {
        $path = $directory . '/' . self::PAYLOAD;
        $hash = hash_file('sha256', $path);
        if (filesize($path) !== $manifest['bytes'] || $hash === false || !hash_equals((string) $manifest['sha256'], $hash)) {
            throw new BackupException('Backup payload size or SHA-256 does not match its manifest.');
        }
    }

    /** @param array<string, mixed> $manifest */
    private function encrypted(array $manifest): bool
    {
        return is_array($manifest['encryption'] ?? null);
    }

    private function validEncryption(mixed $encryption): bool
    {
        return $encryption === 'none'
            || (is_array($encryption)
                && ($encryption['algorithm'] ?? null) === 'secretstream_xchacha20poly1305'
                && ($encryption['kdf'] ?? null)       === 'argon2id13'
                && ($encryption['format'] ?? null)    === 'towerdns/backup-payload-encrypted-v1');
    }

    /** @param array<string,mixed> $manifest */
    private function copyCheckedPayload(string $source, string $target, array $manifest, ?string $passphrase): void
    {
        if ($this->encrypted($manifest)) {
            if ($passphrase === null) {
                throw new BackupException('This backup is encrypted. Supply its passphrase interactively before restore.');
            }
            BackupPayloadCipher::decrypt($source . '/' . self::PAYLOAD, $target . '/' . self::PAYLOAD, $passphrase);
        } elseif (!copy($source . '/' . self::PAYLOAD, $target . '/' . self::PAYLOAD) || !chmod($target . '/' . self::PAYLOAD, 0o600)) {
            throw new BackupException('Unable to stage a private restore payload.');
        }
        if (!$this->encrypted($manifest)) {
            $this->checkPayload($target, $manifest);
        }
    }

    private function assertEmptyServer(Connection $server, string $type): void
    {
        $schema = $server->createSchemaManager();
        $other  = $type === 'mariadb'
            ? 'SELECT COUNT(*) FROM information_schema.routines WHERE routine_schema = DATABASE()'
            : "SELECT (SELECT COUNT(*) FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace WHERE n.nspname = 'public')
                   + (SELECT COUNT(*) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public')";
        if ($schema->introspectTableNames() !== [] || $schema->introspectViews() !== [] || (int) $server->fetchOne($other) !== 0) {
            throw new BackupException('Restore target is not empty. Existing database objects will not be overwritten.');
        }
    }

    private function newPath(string $path): string
    {
        if ($path === '' || in_array(basename($path), ['.', '..'], true)) {
            throw new BackupException('An explicit new destination path is required.');
        }
        $parent = $this->privateDirectory(dirname($path));
        return $parent . '/' . basename($path);
    }

    private function privateDirectory(string $path): string
    {
        $resolved = realpath($path);
        $root     = realpath($this->projectRoot);
        if (is_link($path) || $resolved === false || $root === false || !is_dir($resolved)
                           || $resolved === $root || str_starts_with($resolved, $root . '/')
                           || ((int) fileperms($resolved) & 0o777) !== 0o700) {
            throw new BackupException('Use a mode-0700 directory outside the TowerDNS checkout and every webroot.');
        }
        return $resolved;
    }

    private function createScratch(string $parent, string $prefix = 'towerdns-restore-'): string
    {
        $path = $parent . '/' . $prefix . bin2hex(random_bytes(12));
        if (!mkdir($path, 0o700)) {
            throw new BackupException('Unable to create a private restore staging directory.');
        }
        return $path;
    }

    /** @param list<string> $files */
    private function removeScratch(string $directory, array $files): void
    {
        foreach ($files as $file) {
            if (is_file($directory . '/' . $file)) {
                unlink($directory . '/' . $file);
            }
        }
        rmdir($directory);
    }
}

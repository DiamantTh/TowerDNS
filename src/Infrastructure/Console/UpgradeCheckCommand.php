<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Console;

use Devium\Toml\Toml;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TowerDNS\Application\Module\LocalModuleDiscovery;
use TowerDNS\Infrastructure\Persistence\SchemaMigrationManager;
use TowerDNS\Infrastructure\Ui\AssetManifest;

/** Read-only preflight for an operator-controlled application upgrade. */
#[AsCommand(name: 'towerdns:upgrade:check', description: 'Check runtime and migration prerequisites before an upgrade')]
final class UpgradeCheckCommand extends Command
{
    public function __construct(
        private readonly string $projectRoot,
        private readonly Connection $connection,
        private readonly SchemaMigrationManager $migrations,
        private readonly LocalModuleDiscovery $modules,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: table or json', 'table', ['table', 'json']);
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $checks = [];
        $add    = static function (string $name, bool $ok, string $status, string $detail = '') use (&$checks): void {
            $checks[] = ['check' => $name, 'ok' => $ok, 'status' => $status, 'detail' => $detail];
        };

        $add('php_version', PHP_VERSION_ID >= 80403, PHP_VERSION, 'requires >= 8.4.3');
        foreach (['pdo', 'openssl', 'sodium', 'intl', 'mbstring'] as $extension) {
            $add('extension.' . $extension, extension_loaded($extension), extension_loaded($extension) ? 'available' : 'missing');
        }

        try {
            $platform             = $this->connection->getDatabasePlatform();
            [$driver, $extension] = match (true) {
                $platform instanceof SQLitePlatform        => ['pdo_sqlite', 'pdo_sqlite'],
                $platform instanceof AbstractMySQLPlatform => ['pdo_mysql', 'pdo_mysql'],
                $platform instanceof PostgreSQLPlatform    => ['pdo_pgsql', 'pdo_pgsql'],
                default                                    => ['unsupported', ''],
            };
            $driverReady = $extension !== '' && extension_loaded($extension);
            /** @psalm-suppress InternalMethod Inspect the configured SQLite path before DBAL can open or create the file. */
            $sqlitePath = $this->connection->getParams()['path'] ?? null;
            if ($driver === 'pdo_sqlite' && is_string($sqlitePath) && !is_file($sqlitePath)) {
                throw new \RuntimeException('SQLite database is missing.');
            }
            $this->connection->executeQuery('SELECT 1');
            $add('database', $driverReady, $driver, $driverReady ? 'connection successful' : 'unsupported or unavailable PDO driver');
            if ($driverReady) {
                $version = (string) $this->connection->fetchOne($driver === 'pdo_sqlite' ? 'SELECT sqlite_version()' : 'SELECT VERSION()');
                $add('database_version', true, $version);
            }
        } catch (\Throwable) {
            $driver ??= 'unknown';
            $add('database', false, $driver, 'connection failed; credentials are not displayed');
        }

        $localConfig = $this->readToml('configs/config.local.toml');
        $add('config.local', $localConfig !== null, $localConfig === null ? 'missing or invalid' : 'valid');
        $encodedKey = is_array($localConfig) ? ($localConfig['security']['encryption_key'] ?? null) : null;
        $key        = is_string($encodedKey) ? base64_decode($encodedKey, true) : false;
        $keyReady   = is_string($key) && strlen($key) === 32;
        $add('application_key', $keyReady, $keyReady ? 'configured' : 'missing or invalid', 'secret value is never displayed');
        $dbConfig = $this->readToml('configs/database.toml');
        $add('config.database', $dbConfig !== null, $dbConfig === null ? 'missing or invalid' : 'valid');
        foreach (['data', 'cache/ratelimit', 'logs'] as $directory) {
            $path     = $this->projectRoot . '/' . $directory;
            $writable = is_dir($path) && is_writable($path);
            $add('path.' . $directory, $writable, $writable ? 'writable' : 'missing or not writable');
        }

        try {
            $this->modules->discover();
            $add('modules', true, 'compatible');
        } catch (\Throwable) {
            $add('modules', false, 'invalid', 'module configuration or manifests could not be loaded');
        }

        try {
            new AssetManifest($this->projectRoot)->verifyComplete();
            $add('frontend_release', true, 'valid', 'activated frontend inventory matches source and assets');
        } catch (\Throwable) {
            $add('frontend_release', false, 'invalid', 'frontend release is missing or inconsistent');
        }

        try {
            $schema                     = $this->migrations->status();
            $pendingSchemaAdditionsOnly = $schema->metadataInitialized
                                                         && $schema->pending !== []
                                                         && array_all($schema->schemaIssues, static fn(string $issue): bool => preg_match('/\Amissing (?:table|column|index|foreign key)\b/', $issue) === 1);
            $schemaOk     = $schema->dataIssues   === [] && ($schema->schemaIssues === [] || $pendingSchemaAdditionsOnly);
            $schemaStatus = $schema->schemaIssues === [] && $schema->dataIssues === []
                ? 'valid'
                : ($pendingSchemaAdditionsOnly && $schema->dataIssues === [] ? 'awaiting pending migration' : 'inconsistent');
            $details = [...$schema->schemaIssues, ...$schema->dataIssues];
            $add('schema_integrity', $schemaOk, $schemaStatus, implode('; ', $details));
            $metadataOk = $schema->metadataInitialized || $schema->canBaseline;
            $add('migration_metadata', $metadataOk, $schema->metadataInitialized ? 'initialized' : ($schema->canBaseline ? 'baseline available' : 'invalid or missing'));
            $pending = count($schema->pending);
            $add('pending_migrations', true, (string) $pending, $pending === 0 ? 'database is current' : 'apply only after a verified backup');
        } catch (\Throwable) {
            $add('schema_integrity', false, 'unavailable', 'migration status could not be read');
            $add('migration_metadata', false, 'unavailable');
            $add('pending_migrations', false, 'unavailable');
        }

        $ready = array_all($checks, static fn(array $check): bool => $check['ok']);
        if ((string) $input->getOption('format') === 'json') {
            $output->writeln(json_encode(['ready' => $ready, 'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $io = new SymfonyStyle($input, $output);
            $io->title('TowerDNS upgrade preflight (read-only)');
            foreach ($checks as $check) {
                $label = $check['ok'] ? 'OK' : 'BLOCKED';
                $io->text(sprintf('%s  %s: %s%s', $label, $check['check'], $check['status'], $check['detail'] === '' ? '' : ' — ' . $check['detail']));
            }
            $ready ? $io->success('Preflight checks passed. Pending migrations still require a verified backup and explicit migration command.') : $io->error('Upgrade prerequisites need attention. No files or database rows were changed.');
        }

        return $ready ? Command::SUCCESS : Command::FAILURE;
    }

    /** @return array<string, mixed>|null */
    private function readToml(string $relativePath): ?array
    {
        $path = $this->projectRoot . '/' . $relativePath;
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        try {
            $raw = file_get_contents($path);
            if (!is_string($raw) || $raw === '') {
                return null;
            }
            $data = Toml::decode($raw, asArray: true);
            return is_array($data) ? $data : null;
        } catch (\Throwable) {
            return null;
        }
    }
}

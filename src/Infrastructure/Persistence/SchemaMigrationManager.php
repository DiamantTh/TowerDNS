<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\AvailableMigration;
use Doctrine\Migrations\MigratorConfiguration;

/**
 * Application-facing wrapper around Doctrine Migrations.
 *
 * Schema changes are only executed through migrate(), which is called by the
 * installers or an explicit administrator command/page. HTTP bootstrap never
 * invokes this service.
 */
final readonly class SchemaMigrationManager
{
    public const string METADATA_TABLE = 'towerdns_schema_migrations';

    /** @var list<class-string> */
    private const array MIGRATIONS = [
        Migrations\Version20260919000100::class,
        Migrations\Version20260919000200::class,
    ];

    public function __construct(
        private Connection $connection,
        private ?string $lockPath = null,
    ) {}

    public function status(): SchemaMigrationStatus
    {
        $schema              = new SchemaManager($this->connection);
        $issues              = $schema->schemaIssues();
        $dataIssues          = $schema->dataIssues();
        $metadataInitialized = $this->metadataTableExists();
        $factory             = $this->dependencyFactory();
        $executedVersions    = [];
        $unknownVersions     = [];
        $availableVersions   = [];
        foreach ($factory->getMigrationPlanCalculator()->getMigrations()->getItems() as $migration) {
            $availableVersions[(string) $migration->getVersion()] = true;
        }
        if ($metadataInitialized) {
            foreach ($factory->getMetadataStorage()->getExecutedMigrations()->getItems() as $migration) {
                $version                    = (string) $migration->getVersion();
                $executedVersions[$version] = true;
                if (!isset($availableVersions[$version])) {
                    $unknownVersions[] = sprintf('unknown executed migration %s', $version);
                }
            }
        }

        $executed = [];
        $pending  = [];
        foreach ($factory->getMigrationPlanCalculator()->getMigrations()->getItems() as $migration) {
            $entry = [
                'version'     => (string) $migration->getVersion(),
                'description' => $migration->getMigration()->getDescription(),
            ];
            if (isset($executedVersions[$entry['version']])) {
                $executed[] = $entry;
            } else {
                $pending[] = $entry;
            }
        }

        $schemaCurrent = $schema->schemaIsCurrent() && $unknownVersions === [];

        return new SchemaMigrationStatus(
            $metadataInitialized,
            !$metadataInitialized && $schemaCurrent && $dataIssues === [],
            $schemaCurrent,
            $executed,
            $pending,
            [...$issues, ...$unknownVersions],
            $dataIssues,
        );
    }

    public function migrate(): SchemaMigrationStatus
    {
        $lock = new SchemaMigrationLock($this->connection, $this->lockPath)->acquire();
        try {
            $factory  = $this->dependencyFactory();
            $metadata = $factory->getMetadataStorage();
            $metadata->ensureInitialized();
            $executed          = $metadata->getExecutedMigrations();
            $available         = $factory->getMigrationPlanCalculator()->getMigrations()->getItems();
            $availableVersions = array_fill_keys(array_map(
                static fn(AvailableMigration $migration): string => (string) $migration->getVersion(),
                $available,
            ), true);
            foreach ($executed->getItems() as $migration) {
                if (!isset($availableVersions[(string) $migration->getVersion()])) {
                    throw new \RuntimeException('The database contains a migration version unknown to this application.');
                }
            }

            $schema = new SchemaManager($this->connection);
            if ($executed->count() === 0 && $schema->schemaIsCurrent() && $schema->dataIssues() === []) {
                $this->baseline($available);
                return $this->status();
            }

            if ($available !== []) {
                $latest = end($available);
                if ($latest === false) {
                    throw new \RuntimeException('No migration target could be resolved.');
                }
                $plan = $factory->getMigrationPlanCalculator()->getPlanUntilVersion($latest->getVersion());
                if (count($plan) > 0) {
                    $factory->getMigrator()->migrate($plan, new MigratorConfiguration()->setAllOrNothing(false));
                }
            }

            $issues = new SchemaManager($this->connection);
            if ($issues->schemaIssues() !== []) {
                throw new \RuntimeException('Schema validation failed after migration.');
            }

            return $this->status();
        } finally {
            $lock->release();
        }
    }

    /** @param array<AvailableMigration> $available */
    private function baseline(array $available): void
    {
        $now = new \DateTimeImmutable()->format('Y-m-d H:i:s');
        foreach ($available as $migration) {
            $this->connection->insert(self::METADATA_TABLE, [
                'version'        => (string) $migration->getVersion(),
                'executed_at'    => $now,
                'execution_time' => 0,
            ]);
        }
    }

    private function metadataTableExists(): bool
    {
        return $this->connection->createSchemaManager()->tablesExist([self::METADATA_TABLE]);
    }

    private function dependencyFactory(): DependencyFactory
    {
        $configuration = new ConfigurationArray([
            'migrations'    => self::MIGRATIONS,
            'table_storage' => [
                'table_name'                 => self::METADATA_TABLE,
                'version_column_name'        => 'version',
                'executed_at_column_name'    => 'executed_at',
                'execution_time_column_name' => 'execution_time',
            ],
            'all_or_nothing' => false,
            'transactional'  => true,
        ]);

        return DependencyFactory::fromConnection($configuration, new ExistingConnection($this->connection));
    }
}

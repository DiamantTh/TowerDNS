<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;

final readonly class SchemaMigrationLock
{
    public function __construct(
        private Connection $connection,
        private ?string $lockPath = null,
    ) {}

    public function acquire(): SchemaMigrationLockHandle
    {
        $platform = $this->connection->getDatabasePlatform()->getName();
        if ($platform === 'mysql') {
            $locked = $this->connection->fetchOne("SELECT GET_LOCK('towerdns_schema_migrations', 0)");
            if ((int) $locked !== 1) {
                throw new SchemaMigrationLockedException();
            }

            return SchemaMigrationLockHandle::database($this->connection, 'mysql');
        }

        if ($platform === 'postgresql') {
            $locked = $this->connection->fetchOne("SELECT pg_try_advisory_lock(hashtext('towerdns_schema_migrations'))");
            if (!in_array($locked, [true, 1, '1', 't', 'true'], true)) {
                throw new SchemaMigrationLockedException();
            }

            return SchemaMigrationLockHandle::database($this->connection, 'postgresql');
        }

        $path      = $this->lockPath ?? sys_get_temp_dir() . '/towerdns-schema-migrations.lock';
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0o750, true) && !is_dir($directory)) {
            throw new \RuntimeException('Schema migration lock directory is not available.');
        }
        $handle = fopen($path, 'c');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new SchemaMigrationLockedException();
        }

        return SchemaMigrationLockHandle::file($handle);
    }
}

<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;

final class SchemaMigrationLockHandle
{
    /** @param resource|null $fileHandle */
    private function __construct(
        private readonly ?Connection $connection,
        private readonly ?string $databasePlatform,
        private $fileHandle,
    ) {}

    public static function database(Connection $connection, string $platform): self
    {
        return new self($connection, $platform, null);
    }

    /** @param resource $handle */
    public static function file($handle): self
    {
        return new self(null, null, $handle);
    }

    public function release(): void
    {
        if ($this->connection instanceof Connection) {
            if ($this->databasePlatform === 'mysql') {
                $this->connection->fetchOne("SELECT RELEASE_LOCK('towerdns_schema_migrations')");
            } elseif ($this->databasePlatform === 'postgresql') {
                $this->connection->fetchOne("SELECT pg_advisory_unlock(hashtext('towerdns_schema_migrations'))");
            }
        }

        if (is_resource($this->fileHandle)) {
            flock($this->fileHandle, LOCK_UN);
            fclose($this->fileHandle);
        }
    }
}

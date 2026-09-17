<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;

/** Ensures SQLite connections enforce foreign-key integrity during bootstrap and runtime. */
final readonly class SqliteConnectionConfigurator
{
    public function configure(Connection $connection): void
    {
        if ($connection->getDatabasePlatform()->getName() !== 'sqlite') {
            return;
        }

        $connection->executeStatement('PRAGMA foreign_keys = ON');
    }
}

<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Persistence\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use TowerDNS\Infrastructure\Persistence\SchemaManager;

/** Backfills personal accounts and profile preferences for known older schemas. */
final class Version20260919000200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Backfill explicit account types, personal accounts and profile preferences';
    }

    public function up(Schema $schema): void
    {
        $schemaManager = new SchemaManager($this->connection);
        $schemaManager->seedSystemRoles();
        $schemaManager->seedSystemSettingsDefaults();
        $schemaManager->backfillLegacyProfileAndAccountData();
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Personal-account backfills are intentionally irreversible.');
    }
}

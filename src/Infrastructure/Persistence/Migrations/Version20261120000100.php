<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Persistence\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use TowerDNS\Infrastructure\Persistence\SchemaManager;

/** Enable scoped automation credentials without activating legacy keys. */
final class Version20261120000100 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add scoped TLSA automation keys';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        new SchemaManager($this->connection)->mergeCanonicalSchema($schema);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Scoped API keys must not be converted into unscoped keys.');
    }
}

<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Persistence\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use TowerDNS\Infrastructure\Persistence\SchemaManager;

/** Establishes the additive canonical TowerDNS schema. */
final class Version20260919000100 extends AbstractMigration
{
    private bool $legacyLanguageColumnMissing = false;

    public function isTransactional(): bool
    {
        // DDL transaction semantics differ between SQLite, MySQL/MariaDB and
        // PostgreSQL. Doctrine executes this migration in explicit order, but
        // the database remains responsible for its own DDL boundaries.
        return false;
    }

    public function getDescription(): string
    {
        return 'Establish canonical TowerDNS tables and constraints';
    }

    public function up(Schema $schema): void
    {
        $this->legacyLanguageColumnMissing = $schema->hasTable('users')
            && !$schema->getTable('users')->hasColumn('language');
        new SchemaManager($this->connection)->mergeCanonicalSchema($schema);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The canonical baseline cannot be reverted automatically.');
    }

    public function postUp(Schema $schema): void
    {
        if ($this->legacyLanguageColumnMissing) {
            // Older installations used locale for the UI language. The new
            // column is created with a safe default, then populated only when
            // we know the column did not exist before this migration.
            $this->connection->executeStatement("UPDATE users SET language = locale WHERE locale IS NOT NULL AND locale <> ''");
        }
        $issues = new SchemaManager($this->connection)->schemaIssues();
        $this->abortIf($issues !== [], 'Canonical schema is incomplete: ' . implode('; ', $issues));
    }
}

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
    private bool $legacyLanguageColumnMissing               = false;
    private bool $legacyWebAuthnCredentialIdNeedsConversion = false;

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
        $credentialIdType = $schema->hasTable('webauthn_credentials')
            && $schema->getTable('webauthn_credentials')->hasColumn('credential_id')
            ? $schema->getTable('webauthn_credentials')->getColumn('credential_id')->getType()->getName()
            : null;
        $this->legacyWebAuthnCredentialIdNeedsConversion = $credentialIdType !== null
            && !in_array($credentialIdType, ['binary', 'blob'], true)
            && $this->connection->getDatabasePlatform()->getName() !== 'sqlite';
        new SchemaManager($this->connection)->mergeCanonicalSchema($schema);

        if (!$this->legacyWebAuthnCredentialIdNeedsConversion) {
            return;
        }

        if ($this->connection->getDatabasePlatform()->getName() === 'mysql') {
            $this->addSql('ALTER TABLE webauthn_credentials MODIFY credential_id VARBINARY(1024) NOT NULL');
            return;
        }

        if ($this->connection->getDatabasePlatform()->getName() === 'postgresql') {
            $this->addSql("ALTER TABLE \"webauthn_credentials\" ALTER COLUMN \"credential_id\" TYPE BYTEA USING convert_to(\"credential_id\", 'UTF8')");
        }
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

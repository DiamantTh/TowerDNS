<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Persistence\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use TowerDNS\Infrastructure\Persistence\SchemaManager;

/** Adds bounded credential recovery state and a per-user session generation. */
final class Version20261007000100 extends AbstractMigration
{
    #[\Override]
    public function isTransactional(): bool
    {
        return false;
    }

    #[\Override]
    public function getDescription(): string
    {
        return 'Add password-independent account recovery state';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        new SchemaManager($this->connection)->mergeCanonicalSchema($schema);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Recovery authorization and session-generation state are security state.');
    }

    #[\Override]
    public function postUp(Schema $schema): void
    {
        $manager = $this->connection->createSchemaManager();
        $this->abortIf(
            !$manager->tablesExist(['account_recoveries', 'account_recovery_credentials'])
            || !$manager->introspectTableByUnquotedName('users')->hasColumn('auth_session_version'),
            'Account recovery schema was not created completely.',
        );
    }
}

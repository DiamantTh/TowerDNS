<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Persistence\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use TowerDNS\Infrastructure\Persistence\SchemaManager;

/** Persist provider verification status separately from administrative activation. */
final class Version20261114000100 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Persist provider connection verification status';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        new SchemaManager($this->connection)->mergeCanonicalSchema($schema);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Provider connection history must not be discarded automatically.');
    }

    #[\Override]
    public function postUp(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->createSchemaManager()->introspectTableByUnquotedName('provider_accounts')->hasColumn('last_success_at')
            || !$this->connection->createSchemaManager()->introspectTableByUnquotedName('provider_accounts')->hasColumn('last_error_category')
            || !$this->connection->createSchemaManager()->introspectTableByUnquotedName('provider_accounts')->hasColumn('creation_request_id'),
            'Provider verification status schema was not created completely.',
        );
    }
}

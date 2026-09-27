<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Persistence\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use TowerDNS\Infrastructure\Persistence\SchemaManager;

/** Adds database-enforced one-time claims for action-bound MFA step-up proofs. */
final class Version20260927000100 extends AbstractMigration
{
    public function isTransactional(): bool
    {
        // Keep behavior consistent across SQLite, MariaDB and PostgreSQL DDL.
        return false;
    }

    public function getDescription(): string
    {
        return 'Add one-time step-up proof nonce storage';
    }

    public function up(Schema $schema): void
    {
        if (!$this->connection->createSchemaManager()->tablesExist(['step_up_proof_nonces'])) {
            new SchemaManager($this->connection)->createStepUpProofNonceTableIfMissing();
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Consumed MFA proof nonces are security state and are not rolled back automatically.');
    }

    public function postUp(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->createSchemaManager()->tablesExist(['step_up_proof_nonces']),
            'The step-up proof nonce table was not created.',
        );
    }
}

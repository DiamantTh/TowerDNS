<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Persistence\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use TowerDNS\Infrastructure\Persistence\SchemaManager;

/** Adds independently managed TOTP credentials and WebAuthn metadata. */
final class Version20261004000100 extends AbstractMigration
{
    #[\Override]
    public function isTransactional(): bool
    {
        return false;
    }

    #[\Override]
    public function getDescription(): string
    {
        return 'Add multi-TOTP credentials and WebAuthn enrollment metadata';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        new SchemaManager($this->connection)->mergeCanonicalSchema($schema);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Migrated authentication factors are retained for account safety.');
    }

    #[\Override]
    public function postUp(Schema $schema): void
    {
        $now  = new \DateTimeImmutable()->format('Y-m-d H:i:s');
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, totp_secret_encrypted, created_at FROM users WHERE totp_secret_encrypted IS NOT NULL AND totp_secret_encrypted <> ?',
            [''],
        );

        foreach ($rows as $row) {
            $userId = (string) $row['id'];
            $id     = $this->stableLegacyCredentialId($userId);
            $exists = $this->connection->fetchOne('SELECT id FROM totp_credentials WHERE id = ?', [$id]);
            if ($exists === false) {
                $createdAt = $row['created_at'] ?? null;
                $this->connection->insert('totp_credentials', [
                    'id'               => $id,
                    'user_id'          => $userId,
                    'secret_encrypted' => (string) $row['totp_secret_encrypted'],
                    'label'            => 'Migrated authenticator',
                    'created_at'       => is_string($createdAt) && $createdAt !== '' ? $createdAt : $now,
                    'last_used_at'     => null,
                ]);
            }

            // The durable copy exists before the legacy field is cleared.
            $this->connection->update('users', ['totp_secret_encrypted' => null], ['id' => $userId]);
        }

        new SchemaManager($this->connection)->seedSystemSettingsDefaults();
        $this->abortIf(
            !$this->connection->createSchemaManager()->tablesExist(['totp_credentials'])
            || !$this->connection->createSchemaManager()->introspectTableByUnquotedName('webauthn_credentials')->hasColumn('attachment'),
            'Authentication credential schema was not created completely.',
        );
    }

    private function stableLegacyCredentialId(string $userId): string
    {
        $hex     = substr(hash('sha256', 'towerdns:legacy-totp:' . $userId), 0, 32);
        $hex[12] = '5';
        $hex[16] = dechex((hexdec($hex[16]) & 0x3) | 0x8);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}

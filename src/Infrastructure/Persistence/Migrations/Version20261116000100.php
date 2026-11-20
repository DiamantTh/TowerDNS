<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Persistence\Migrations;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use TowerDNS\Infrastructure\Persistence\SchemaManager;

/** Adds an opaque persistent WebAuthn handle without rewriting existing credentials. */
final class Version20261116000100 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add stable opaque WebAuthn user handles';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        new SchemaManager($this->connection)->mergeCanonicalSchema($schema);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('WebAuthn handles are bound to registered credentials and must be restored from backup, not regenerated.');
    }

    #[\Override]
    public function postUp(Schema $schema): void
    {
        $configuredRpId = $this->connection->fetchOne(
            'SELECT setting_value FROM system_settings WHERE setting_key = ?',
            ['security.webauthn.rp_id'],
        );
        $rpId = null;
        if (is_string($configuredRpId) && $configuredRpId !== '') {
            $decodedRpId = json_decode($configuredRpId, true);
            if (is_string($decodedRpId) && $decodedRpId !== '') {
                $rpId = $decodedRpId;
            } elseif (json_last_error() !== JSON_ERROR_NONE) {
                // Retain compatibility with deployments predating JSON-backed settings.
                $rpId = $configuredRpId;
            }
        }
        $rows = $this->connection->fetchAllAssociative('SELECT id, webauthn_user_handle, webauthn_user_handle_rp_id FROM users');
        $seen = [];

        foreach ($rows as $row) {
            $userId = (string) $row['id'];
            $handle = $row['webauthn_user_handle'] ?? null;
            if (is_resource($handle)) {
                $handle = stream_get_contents($handle);
                if ($handle === false) {
                    throw new \UnexpectedValueException('Could not read the persisted WebAuthn handle.');
                }
            }

            if ($handle === null || $handle === '') {
                $handle = random_bytes(64);
                $this->connection->update(
                    'users',
                    ['webauthn_user_handle' => $handle, 'webauthn_user_handle_rp_id' => $rpId],
                    ['id' => $userId],
                    ['webauthn_user_handle' => ParameterType::BINARY, 'webauthn_user_handle_rp_id' => ParameterType::STRING, 'id' => ParameterType::STRING],
                );
            } elseif (($row['webauthn_user_handle_rp_id'] ?? null) === null && $rpId !== null) {
                $this->connection->update('users', ['webauthn_user_handle_rp_id' => $rpId], ['id' => $userId]);
            }

            $this->abortIf(!is_string($handle) || strlen($handle) !== 64, 'A WebAuthn user handle is missing or has an invalid length.');
            $handleKey = bin2hex($handle);
            $this->abortIf(isset($seen[$handleKey]), 'WebAuthn user handles must be unique.');
            $seen[$handleKey] = true;
        }

        $manager = $this->connection->createSchemaManager();
        $this->abortIf(
            !$manager->introspectTableByUnquotedName('users')->hasColumn('webauthn_user_handle')
            || !$manager->introspectTableByUnquotedName('users')->hasColumn('webauthn_user_handle_rp_id')
            || !$manager->introspectTableByUnquotedName('users')->hasIndex('uq_users_webauthn_user_handle'),
            'The WebAuthn user handle schema was not created completely.',
        );
    }
}

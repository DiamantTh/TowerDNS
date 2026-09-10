<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use TowerDNS\Application\Repository\ProviderAccountRepositoryInterface;
use TowerDNS\Domain\Account\ProviderAccount;

final readonly class DbalProviderAccountRepository implements ProviderAccountRepositoryInterface
{
    public function __construct(private Connection $connection) {}

    public function findById(int $id): ?ProviderAccount
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM provider_accounts WHERE id = ?',
            [$id]
        );
        return $row !== false ? $this->hydrate($row) : null;
    }

    public function findByAccountId(int $accountId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM provider_accounts WHERE account_id = ? ORDER BY name ASC',
            [$accountId]
        );
        return array_map($this->hydrate(...), $rows);
    }

    public function findActiveByAccountAndType(int $accountId, string $providerType): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM provider_accounts
             WHERE account_id = ? AND provider_type = ? AND is_active = 1
             ORDER BY name ASC',
            [$accountId, $providerType]
        );
        return array_map($this->hydrate(...), $rows);
    }

    public function create(
        int    $accountId,
        string $providerType,
        string $name,
        string $credentialsEncrypted,
        int    $credentialsVersion,
        string $createdAt,
    ): int {
        $this->connection->insert('provider_accounts', [
            'account_id'            => $accountId,
            'provider_type'         => $providerType,
            'name'                  => $name,
            'credentials_encrypted' => $credentialsEncrypted,
            'credentials_version'   => $credentialsVersion,
            'is_active'             => 1,
            'created_at'            => $createdAt,
        ]);
        return (int) $this->connection->lastInsertId();
    }

    public function replaceCredentials(
        int    $id,
        int    $accountId,
        string $credentialsEncrypted,
        int    $credentialsVersion,
    ): void {
        $this->connection->update(
            'provider_accounts',
            [
                'credentials_encrypted' => $credentialsEncrypted,
                'credentials_version'   => $credentialsVersion,
            ],
            ['id' => $id, 'account_id' => $accountId]
        );
    }

    public function touchLastUsed(int $id, string $timestamp): void
    {
        $this->connection->update(
            'provider_accounts',
            ['last_used_at' => $timestamp],
            ['id' => $id]
        );
    }

    public function touchLastTested(int $id, string $timestamp): void
    {
        $this->connection->update(
            'provider_accounts',
            ['last_tested_at' => $timestamp],
            ['id' => $id]
        );
    }

    public function deactivate(int $id, int $accountId): void
    {
        $this->connection->update(
            'provider_accounts',
            ['is_active' => 0],
            ['id' => $id, 'account_id' => $accountId]
        );
    }

    public function delete(int $id, int $accountId): void
    {
        $this->connection->delete(
            'provider_accounts',
            ['id' => $id, 'account_id' => $accountId]
        );
    }

    // ── Hydration ─────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): ProviderAccount
    {
        return new ProviderAccount(
            id: (int) $row['id'],
            accountId: (int) $row['account_id'],
            providerType: (string) $row['provider_type'],
            name: (string) $row['name'],
            credentialsEncrypted: (string) $row['credentials_encrypted'],
            credentialsVersion: (int) $row['credentials_version'],
            isActive: (bool) $row['is_active'],
            createdAt: (string) ($row['created_at'] ?? ''),
            lastTestedAt: isset($row['last_tested_at']) ? (string) $row['last_tested_at'] : null,
            lastUsedAt: isset($row['last_used_at']) ? (string) $row['last_used_at'] : null,
        );
    }
}

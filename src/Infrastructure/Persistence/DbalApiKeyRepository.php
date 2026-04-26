<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use TowerDNS\Application\Repository\ApiKeyRepositoryInterface;

final readonly class DbalApiKeyRepository implements ApiKeyRepositoryInterface
{
    public function __construct(private Connection $connection) {}

    public function findByUserId(string $userId): array
    {
        /** @var list<array{id: int|string, name: string, created_at: string|null, last_used: string|null, is_active: int|bool}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, name, created_at, last_used, is_active
               FROM api_keys
              WHERE user_id = ?
              ORDER BY id DESC',
            [$userId],
        );

        return array_map(static function (array $row): array {
            return [
                'id'         => (int) $row['id'],
                'name'       => (string) $row['name'],
                'created_at' => isset($row['created_at']) ? (string) $row['created_at'] : null,
                'last_used'  => isset($row['last_used']) ? (string) $row['last_used'] : null,
                'is_active'  => (bool) $row['is_active'],
            ];
        }, $rows);
    }

    public function create(string $userId, string $name, string $keyHash, string $createdAt): int
    {
        $this->connection->executeStatement(
            'INSERT INTO api_keys (user_id, name, api_key, created_at, is_active)
             VALUES (?, ?, ?, ?, 1)',
            [$userId, $name, $keyHash, $createdAt],
        );

        return (int) $this->connection->lastInsertId();
    }

    public function revoke(int $id, string $userId): bool
    {
        $affected = $this->connection->executeStatement(
            'UPDATE api_keys SET is_active = 0 WHERE id = ? AND user_id = ? AND is_active = 1',
            [$id, $userId],
        );

        return $affected > 0;
    }

    public function findActiveByHash(string $keyHash): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, user_id FROM api_keys WHERE api_key = ? AND is_active = 1',
            [$keyHash],
        );

        if (!is_array($row)) {
            return null;
        }

        return [
            'id'      => (int) $row['id'],
            'user_id' => (string) $row['user_id'],
        ];
    }

    public function touchLastUsed(int $id, string $timestamp): void
    {
        $this->connection->executeStatement(
            'UPDATE api_keys SET last_used = ? WHERE id = ?',
            [$timestamp, $id],
        );
    }
}

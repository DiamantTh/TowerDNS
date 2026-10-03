<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use TowerDNS\Application\Repository\ApiKeyRepositoryInterface;

/** @psalm-api Constructed through runtime dependency injection or command/handler registration. */
final readonly class DbalApiKeyRepository implements ApiKeyRepositoryInterface
{
    public function __construct(private Connection $connection) {}

    #[\Override]
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

        return array_map(static fn(array $row): array => [
            'id'         => (int) $row['id'],
            'name'       => $row['name'],
            'created_at' => $row['created_at'] ?? null,
            'last_used'  => $row['last_used']  ?? null,
            'is_active'  => (bool) $row['is_active'],
        ], $rows);
    }

    #[\Override]
    public function revoke(int $id, string $userId): bool
    {
        $affected = $this->connection->executeStatement(
            'UPDATE api_keys SET is_active = FALSE WHERE id = ? AND user_id = ? AND is_active = TRUE',
            [$id, $userId],
        );

        return $affected > 0;
    }
}

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
    public function create(string $userId, string $name, string $digest, string $scope, int $account, int $zone, ?string $owner, \DateTimeImmutable $expires): void
    {
        $this->connection->insert('api_keys', [
            'user_id'    => $userId, 'name' => $name, 'api_key' => $digest,
            'scope'      => $scope, 'scope_account' => $account, 'scope_zone' => $zone, 'scope_owner' => $owner,
            'created_at' => gmdate('Y-m-d H:i:s'), 'expires_at' => $expires->format('Y-m-d H:i:s'),
        ]);
    }

    #[\Override]
    public function findGrant(string $digest, \DateTimeImmutable $now): ?\TowerDNS\Domain\Auth\ApiKeyGrant
    {
        /** @var array{id:int|string,user_id:string,scope:string,scope_account:int|string,scope_zone:int|string,scope_owner:?string}|false $row */
        $row = $this->connection->fetchAssociative(
            'SELECT id, user_id, scope, scope_account, scope_zone, scope_owner FROM api_keys
             WHERE api_key = ? AND is_active = TRUE AND scope IS NOT NULL AND scope_account IS NOT NULL
             AND scope_zone IS NOT NULL AND expires_at > ?',
            [$digest, $now->format('Y-m-d H:i:s')],
        );
        if ($row === false) {
            return null;
        }
        return new \TowerDNS\Domain\Auth\ApiKeyGrant((int) $row['id'], $row['user_id'], $row['scope'], (int) $row['scope_account'], (int) $row['scope_zone'], $row['scope_owner']);
    }

    #[\Override]
    public function findByUserId(string $userId): array
    {
        /** @var list<array{id: int|string, name: string, created_at: string|null, last_used: string|null, is_active: int|bool, scope: ?string, expires_at: ?string, scope_account: int|string|null, scope_zone: int|string|null, scope_owner: ?string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, name, created_at, last_used, is_active, scope, expires_at, scope_account, scope_zone, scope_owner
               FROM api_keys
              WHERE user_id = ?
              ORDER BY id DESC',
            [$userId],
        );

        return array_map(static fn(array $row): array => [
            'id'            => (int) $row['id'],
            'name'          => $row['name'],
            'created_at'    => $row['created_at'] ?? null,
            'last_used'     => $row['last_used']  ?? null,
            'is_active'     => (bool) $row['is_active'],
            'scope'         => $row['scope'],
            'expires_at'    => $row['expires_at'],
            'scope_account' => (int) $row['scope_account'],
            'scope_zone'    => (int) $row['scope_zone'],
            'scope_owner'   => $row['scope_owner'],
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

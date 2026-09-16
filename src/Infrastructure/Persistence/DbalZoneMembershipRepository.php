<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use TowerDNS\Application\Repository\ZoneMembershipRepositoryInterface;
use TowerDNS\Domain\Account\TeamRole;
use TowerDNS\Domain\Account\ZoneMembership;

final readonly class DbalZoneMembershipRepository implements ZoneMembershipRepositoryInterface
{
    public function __construct(private Connection $connection) {}

    public function findByManagedZoneId(int $managedZoneId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM zone_memberships WHERE managed_zone_id = ? ORDER BY created_at ASC',
            [$managedZoneId]
        );
        return array_map($this->hydrate(...), $rows);
    }

    public function findByUserId(string $userId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM zone_memberships WHERE user_id = ?',
            [$userId]
        );
        return array_map($this->hydrate(...), $rows);
    }

    public function findMembership(int $managedZoneId, string $userId): ?ZoneMembership
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM zone_memberships WHERE managed_zone_id = ? AND user_id = ?',
            [$managedZoneId, $userId]
        );
        return $row !== false ? $this->hydrate($row) : null;
    }

    public function grant(int $managedZoneId, string $userId, TeamRole $role, string $createdAt, ?string $grantedBy = null): void
    {
        $this->connection->insert('zone_memberships', [
            'managed_zone_id' => $managedZoneId,
            'user_id'         => $userId,
            'role'            => $role->value,
            'granted_by'      => $grantedBy,
            'created_at'      => $createdAt,
        ]);
    }

    public function updateRole(int $managedZoneId, string $userId, TeamRole $role): void
    {
        $this->connection->update(
            'zone_memberships',
            ['role' => $role->value],
            ['managed_zone_id' => $managedZoneId, 'user_id' => $userId]
        );
    }

    public function revoke(int $managedZoneId, string $userId): void
    {
        $this->connection->delete(
            'zone_memberships',
            ['managed_zone_id' => $managedZoneId, 'user_id' => $userId]
        );
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): ZoneMembership
    {
        return new ZoneMembership(
            id: (int) $row['id'],
            managedZoneId: (int) $row['managed_zone_id'],
            userId: (string) $row['user_id'],
            role: TeamRole::from((string) $row['role']),
            createdAt: (string) ($row['created_at'] ?? ''),
            grantedByUserId: isset($row['granted_by']) ? (string) $row['granted_by'] : null,
        );
    }
}

<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use TowerDNS\Application\Repository\ZoneMembershipRepositoryInterface;
use TowerDNS\Domain\Account\TeamRole;
use TowerDNS\Domain\Account\ZoneMembership;

final class DbalZoneMembershipRepository implements ZoneMembershipRepositoryInterface
{
    public function __construct(private readonly Connection $connection) {}

    public function findByZoneId(string $zoneId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM zone_memberships WHERE zone_id = ? ORDER BY created_at ASC',
            [$zoneId]
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

    public function findMembership(string $zoneId, string $userId): ?ZoneMembership
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM zone_memberships WHERE zone_id = ? AND user_id = ?',
            [$zoneId, $userId]
        );
        return $row !== false ? $this->hydrate($row) : null;
    }

    public function grant(string $zoneId, string $userId, TeamRole $role, string $createdAt, ?string $grantedBy = null): void
    {
        $this->connection->insert('zone_memberships', [
            'zone_id'    => $zoneId,
            'user_id'    => $userId,
            'role'       => $role->value,
            'granted_by' => $grantedBy,
            'created_at' => $createdAt,
        ]);
    }

    public function updateRole(string $zoneId, string $userId, TeamRole $role): void
    {
        $this->connection->update(
            'zone_memberships',
            ['role'    => $role->value],
            ['zone_id' => $zoneId, 'user_id' => $userId]
        );
    }

    public function revoke(string $zoneId, string $userId): void
    {
        $this->connection->delete(
            'zone_memberships',
            ['zone_id' => $zoneId, 'user_id' => $userId]
        );
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): ZoneMembership
    {
        return new ZoneMembership(
            id: (int) $row['id'],
            zoneId: (string) $row['zone_id'],
            userId: (string) $row['user_id'],
            role: TeamRole::from((string) $row['role']),
            createdAt: (string) ($row['created_at'] ?? ''),
            grantedByUserId: isset($row['granted_by']) ? (string) $row['granted_by'] : null,
        );
    }
}

<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use TowerDNS\Application\Repository\AccountRepositoryInterface;
use TowerDNS\Domain\Account\Account;
use TowerDNS\Domain\Account\AccountMembership;
use TowerDNS\Domain\Account\TeamRole;

final class DbalAccountRepository implements AccountRepositoryInterface
{
    public function __construct(private readonly Connection $connection) {}

    public function findById(int $id): ?Account
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM accounts WHERE id = ?',
            [$id]
        );
        return $row !== false ? $this->hydrate($row) : null;
    }

    public function findBySlug(string $slug): ?Account
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM accounts WHERE slug = ?',
            [$slug]
        );
        return $row !== false ? $this->hydrate($row) : null;
    }

    public function findByUserId(string $userId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT a.* FROM accounts a
             JOIN account_memberships am ON am.account_id = a.id
             WHERE am.user_id = ?
               AND a.is_active = 1
             ORDER BY a.name ASC',
            [$userId]
        );
        return array_map($this->hydrate(...), $rows);
    }

    public function findAll(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM accounts ORDER BY name ASC'
        );
        return array_map($this->hydrate(...), $rows);
    }

    public function create(string $name, string $slug, string $ownerUserId, string $createdAt): int
    {
        $this->connection->insert('accounts', [
            'name'          => $name,
            'slug'          => $slug,
            'owner_user_id' => $ownerUserId,
            'is_active'     => 1,
            'created_at'    => $createdAt,
        ]);
        $id = (int) $this->connection->lastInsertId();

        // Automatically add the owner as a member with owner role
        $this->connection->insert('account_memberships', [
            'account_id' => $id,
            'user_id'    => $ownerUserId,
            'role'       => TeamRole::OWNER->value,
            'invited_by' => null,
            'created_at' => $createdAt,
        ]);

        return $id;
    }

    public function updateName(int $id, string $name): void
    {
        $this->connection->update('accounts', ['name' => $name], ['id' => $id]);
    }

    public function deactivate(int $id): void
    {
        $this->connection->update('accounts', ['is_active' => 0], ['id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->connection->delete('accounts', ['id' => $id]);
    }

    // ── Memberships ───────────────────────────────────────────────────────────

    public function findMemberships(int $accountId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM account_memberships WHERE account_id = ? ORDER BY created_at ASC',
            [$accountId]
        );
        return array_map($this->hydrateMembership(...), $rows);
    }

    public function findMembership(int $accountId, string $userId): ?AccountMembership
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM account_memberships WHERE account_id = ? AND user_id = ?',
            [$accountId, $userId]
        );
        return $row !== false ? $this->hydrateMembership($row) : null;
    }

    public function addMembership(int $accountId, string $userId, TeamRole $role, string $createdAt, ?string $invitedBy = null): void
    {
        $this->connection->insert('account_memberships', [
            'account_id' => $accountId,
            'user_id'    => $userId,
            'role'       => $role->value,
            'invited_by' => $invitedBy,
            'created_at' => $createdAt,
        ]);
    }

    public function updateMembershipRole(int $accountId, string $userId, TeamRole $role): void
    {
        $this->connection->update(
            'account_memberships',
            ['role'       => $role->value],
            ['account_id' => $accountId, 'user_id' => $userId]
        );
    }

    public function removeMembership(int $accountId, string $userId): void
    {
        $this->connection->delete(
            'account_memberships',
            ['account_id' => $accountId, 'user_id' => $userId]
        );
    }

    public function getEffectiveRole(int $accountId, string $userId): ?TeamRole
    {
        $row = $this->connection->fetchAssociative(
            'SELECT role FROM account_memberships WHERE account_id = ? AND user_id = ?',
            [$accountId, $userId]
        );
        if ($row === false) {
            return null;
        }
        return TeamRole::tryFrom((string) $row['role']);
    }

    // ── Hydration ─────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Account
    {
        return new Account(
            id: (int) $row['id'],
            name: (string) $row['name'],
            slug: (string) $row['slug'],
            ownerUserId: (string) $row['owner_user_id'],
            isActive: (bool) $row['is_active'],
            createdAt: (string) ($row['created_at'] ?? ''),
        );
    }

    /** @param array<string, mixed> $row */
    private function hydrateMembership(array $row): AccountMembership
    {
        return new AccountMembership(
            id: (int) $row['id'],
            accountId: (int) $row['account_id'],
            userId: (string) $row['user_id'],
            role: TeamRole::from((string) $row['role']),
            createdAt: (string) ($row['created_at'] ?? ''),
            invitedByUserId: isset($row['invited_by']) ? (string) $row['invited_by'] : null,
        );
    }
}

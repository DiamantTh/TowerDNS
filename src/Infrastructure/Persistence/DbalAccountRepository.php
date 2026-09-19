<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use TowerDNS\Application\Repository\AccountRepositoryInterface;
use TowerDNS\Domain\Account\Account;
use TowerDNS\Domain\Account\AccountKind;
use TowerDNS\Domain\Account\AccountMembership;
use TowerDNS\Domain\Account\TeamRole;

final readonly class DbalAccountRepository implements AccountRepositoryInterface
{
    public function __construct(private Connection $connection) {}

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

    public function findPersonalByUserId(string $userId): ?Account
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM accounts WHERE account_type = ? AND personal_user_id = ?',
            [AccountKind::PERSONAL->value, $userId],
        );
        return $row !== false ? $this->hydrate($row) : null;
    }

    public function findByUserId(string $userId, ?string $search = null, ?AccountKind $type = null, int $limit = 100, int $offset = 0, bool $includeInactive = false, ?TeamRole $role = null, ?bool $active = null): array
    {
        $conditions = ['am.user_id = ?'];
        $params     = [$userId];
        if ($active !== null) {
            $conditions[] = 'a.is_active = ?';
            $params[] = $active ? 1 : 0;
        } elseif (!$includeInactive) {
            $conditions[] = 'a.is_active = 1';
        }
        if ($search !== null && trim($search) !== '') {
            $conditions[] = '(a.name LIKE ? OR a.slug LIKE ? OR a.customer_number LIKE ? OR a.external_reference LIKE ?)';
            $term         = '%' . trim($search) . '%';
            $params[]     = $term;
            $params[]     = $term;
            $params[]     = $term;
            $params[]     = $term;
        }
        if ($type instanceof AccountKind) {
            $conditions[] = 'a.account_type = ?';
            $params[]     = $type->value;
        }
        if ($role instanceof TeamRole) {
            $conditions[] = 'am.role = ?';
            $params[]     = $role->value;
        }
        $params[] = max(1, min($limit, 500));
        $params[] = max(0, $offset);
        $rows     = $this->connection->fetchAllAssociative(
            'SELECT a.* FROM accounts a
             JOIN account_memberships am ON am.account_id = a.id
             WHERE ' . implode(' AND ', $conditions) . '
             ORDER BY a.name ASC LIMIT ? OFFSET ?',
            $params,
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

    public function create(string $name, string $slug, string $ownerUserId, string $createdAt, AccountKind $type = AccountKind::ORGANIZATION, ?string $personalUserId = null, ?string $customerNumber = null, ?string $externalReference = null): int
    {
        if ($type === AccountKind::PERSONAL && $personalUserId !== $ownerUserId) {
            throw new \DomainException('A personal account must be permanently assigned to its owner.');
        }
        return $this->connection->transactional(function () use ($name, $slug, $ownerUserId, $createdAt, $type, $personalUserId, $customerNumber, $externalReference): int {
            $this->connection->insert('accounts', [
                'name'               => $name,
                'slug'               => $slug,
                'owner_user_id'      => $ownerUserId,
                'account_type'       => $type->value,
                'personal_user_id'   => $personalUserId,
                'customer_number'    => $customerNumber,
                'external_reference' => $externalReference,
                'is_active'          => 1,
                'created_at'         => $createdAt,
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
            $this->connection->insert('account_resource_limits', [
                'account_id'            => $id,
                'max_zones'             => null,
                'max_members'           => null,
                'max_provider_accounts' => null,
            ]);

            return $id;
        });
    }

    public function updateName(int $id, string $name): void
    {
        $this->connection->update('accounts', ['name' => $name], ['id' => $id]);
    }

    public function updateOrganizationDetails(int $id, string $name, ?string $customerNumber, ?string $externalReference): void
    {
        $this->connection->update('accounts', [
            'name'               => $name,
            'customer_number'    => $customerNumber,
            'external_reference' => $externalReference,
        ], ['id' => $id]);
    }

    public function deactivate(int $id): void
    {
        $this->connection->update('accounts', ['is_active' => 0], ['id' => $id]);
    }

    public function activate(int $id): void
    {
        $this->connection->update('accounts', ['is_active' => 1], ['id' => $id]);
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
            ['role' => $role->value],
            ['account_id' => $accountId, 'user_id' => $userId]
        );
    }

    public function removeMembership(int $accountId, string $userId): void
    {
        $membership = $this->findMembership($accountId, $userId);
        if ($membership?->role === TeamRole::OWNER) {
            throw new \DomainException('Account owners must be transferred before removal.');
        }
        $this->connection->delete(
            'account_memberships',
            ['account_id' => $accountId, 'user_id' => $userId]
        );
    }

    public function transferOwnership(int $accountId, string $newOwnerUserId): void
    {
        $this->connection->transactional(function () use ($accountId, $newOwnerUserId): void {
            $account = $this->findById($accountId);
            if (!$account instanceof Account) {
                throw new \DomainException('Account not found.');
            }
            if ($account->kind() === AccountKind::PERSONAL) {
                throw new \DomainException('Personal account ownership cannot be transferred.');
            }

            $target = $this->findMembership($accountId, $newOwnerUserId);
            if (!$target instanceof AccountMembership) {
                throw new \DomainException('New owner must already be an account member.');
            }

            if ($account->ownerUserId !== $newOwnerUserId) {
                $this->connection->update(
                    'account_memberships',
                    ['role' => TeamRole::ADMIN->value],
                    ['account_id' => $accountId, 'user_id' => $account->ownerUserId],
                );
            }

            $this->connection->update(
                'account_memberships',
                ['role' => TeamRole::OWNER->value],
                ['account_id' => $accountId, 'user_id' => $newOwnerUserId],
            );
            $this->connection->update('accounts', ['owner_user_id' => $newOwnerUserId], ['id' => $accountId]);
        });
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
            type: AccountKind::tryFrom((string) ($row['account_type'] ?? '')) ?? AccountKind::ORGANIZATION,
            personalUserId: isset($row['personal_user_id'])      && $row['personal_user_id']   !== '' ? (string) $row['personal_user_id'] : null,
            customerNumber: isset($row['customer_number'])       && $row['customer_number']    !== '' ? (string) $row['customer_number'] : null,
            externalReference: isset($row['external_reference']) && $row['external_reference'] !== '' ? (string) $row['external_reference'] : null,
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

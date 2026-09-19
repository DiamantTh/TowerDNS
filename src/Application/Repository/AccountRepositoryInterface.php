<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Repository;

use TowerDNS\Domain\Account\Account;
use TowerDNS\Domain\Account\AccountKind;
use TowerDNS\Domain\Account\AccountMembership;
use TowerDNS\Domain\Account\TeamRole;

interface AccountRepositoryInterface
{
    public function findById(int $id): ?Account;

    public function findBySlug(string $slug): ?Account;

    public function findPersonalByUserId(string $userId): ?Account;

    /**
     * Returns all accounts the given user is a member of (any role).
     * @return list<Account>
     */
    public function findByUserId(string $userId, ?string $search = null, ?AccountKind $type = null, int $limit = 100, int $offset = 0, bool $includeInactive = false, ?TeamRole $role = null, ?bool $active = null): array;

    /**
     * Returns all accounts (system-admin view).
     * @return list<Account>
     */
    public function findAll(): array;

    public function create(string $name, string $slug, string $ownerUserId, string $createdAt, AccountKind $type = AccountKind::ORGANIZATION, ?string $personalUserId = null, ?string $customerNumber = null, ?string $externalReference = null): int;

    public function updateName(int $id, string $name): void;

    public function updateOrganizationDetails(int $id, string $name, ?string $customerNumber, ?string $externalReference): void;

    public function deactivate(int $id): void;

    public function activate(int $id): void;

    public function delete(int $id): void;

    // ── Memberships ───────────────────────────────────────────────────────────

    /**
     * @return list<AccountMembership>
     */
    public function findMemberships(int $accountId): array;

    public function findMembership(int $accountId, string $userId): ?AccountMembership;

    public function addMembership(int $accountId, string $userId, TeamRole $role, string $createdAt, ?string $invitedBy = null): void;

    public function updateMembershipRole(int $accountId, string $userId, TeamRole $role): void;

    public function removeMembership(int $accountId, string $userId): void;

    public function transferOwnership(int $accountId, string $newOwnerUserId): void;

    /**
     * Returns the effective TeamRole for a user in an account, or null if not a member.
     */
    public function getEffectiveRole(int $accountId, string $userId): ?TeamRole;
}

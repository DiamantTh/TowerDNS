<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Repository;

/**
 * Persistence contract for scoped API keys. Legacy records cannot authenticate.
 *
 * The plaintext token is NEVER stored.  Only the SHA-256 hex digest is kept in
 * the database, so even with full DB read-access an attacker cannot impersonate
 * a key holder.
 */
/** @psalm-api Persistence contract for the account-bound API-key lifecycle. */
interface ApiKeyRepositoryInterface
{
    public function create(string $userId, string $name, string $digest, string $scope, int $account, int $zone, ?string $owner, \DateTimeImmutable $expires): void;

    public function findGrant(string $digest, \DateTimeImmutable $now): ?\TowerDNS\Domain\Auth\ApiKeyGrant;

    /**
     * All keys (active and revoked) belonging to a user.
     *
     * @return list<array{id: int, name: string, created_at: ?string, last_used: ?string, is_active: bool, scope: ?string, expires_at: ?string, scope_account: int, scope_zone: int, scope_owner: ?string}>
     */
    public function findByUserId(string $userId): array;

    /**
     * Deactivate a single key.  Ownership check (user_id) is enforced here.
     *
     * @return bool  true if a row was actually updated
    */
    public function revoke(int $id, string $userId): bool;
}

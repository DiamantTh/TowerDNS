<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Repository;

/**
 * Persistence contract for legacy API-key records that can be inspected and revoked.
 *
 * The plaintext token is NEVER stored.  Only the SHA-256 hex digest is kept in
 * the database, so even with full DB read-access an attacker cannot impersonate
 * a key holder.
 */
/** @psalm-api Persistence contract for the account-bound API-key lifecycle. */
interface ApiKeyRepositoryInterface
{
    /**
     * All keys (active and revoked) belonging to a user.
     *
     * @return list<array{id: int, name: string, created_at: ?string, last_used: ?string, is_active: bool}>
     */
    public function findByUserId(string $userId): array;

    /**
     * Deactivate a single key.  Ownership check (user_id) is enforced here.
     *
     * @return bool  true if a row was actually updated
    */
    public function revoke(int $id, string $userId): bool;
}

<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Repository;

/**
 * Persistence contract for user API keys.
 *
 * The plaintext token is NEVER stored.  Only the SHA-256 hex digest is kept in
 * the database, so even with full DB read-access an attacker cannot impersonate
 * a key holder.
 */
interface ApiKeyRepositoryInterface
{
    /**
     * All keys (active and revoked) belonging to a user.
     *
     * @return list<array{id: int, name: string, created_at: ?string, last_used: ?string, is_active: bool}>
     */
    public function findByUserId(string $userId): array;

    /**
     * Persist a new key.  $keyHash must be hash('sha256', $plainToken).
     *
     * @return int  auto-generated key ID
     */
    public function create(string $userId, string $name, string $keyHash, string $createdAt): int;

    /**
     * Deactivate a single key.  Ownership check (user_id) is enforced here.
     *
     * @return bool  true if a row was actually updated
     */
    public function revoke(int $id, string $userId): bool;

    /**
     * Look up an active key by its SHA-256 hash for incoming API requests.
     * Returns a minimal record or null when not found / inactive.
     *
     * @return array{id: int, user_id: string}|null
     */
    public function findActiveByHash(string $keyHash): ?array;

    /**
     * Update the last_used timestamp for rate-limiting / audit purposes.
     */
    public function touchLastUsed(int $id, string $timestamp): void;
}

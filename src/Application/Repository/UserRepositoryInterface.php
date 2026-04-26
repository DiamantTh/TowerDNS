<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Repository;

use TowerDNS\Domain\Auth\User;

interface UserRepositoryInterface
{
    /**
     * Loads a user with all assigned roles and their permissions.
     * Returns null when no user with that ID exists.
     */
    public function findById(string $id): ?User;

    /**
     * Loads a user by e-mail address.
     * Returns null when no user with that address exists.
     */
    public function findByEmail(string $email): ?User;

    /**
     * Returns the stored password hash (bcrypt / Argon2id) for the given
     * e-mail address. Returns null when the address is unknown.
     *
     * Only use the return value with {@see password_verify()} — never expose
     * the hash to callers outside the authentication flow.
     */
    public function fetchPasswordHash(string $email): ?string;

    /**
     * Returns the base32-encoded TOTP secret for the given user, or null
     * when TOTP is not configured.
     */
    public function fetchTotpSecret(string $userId): ?string;

    /**
     * Persists (or clears) the TOTP secret for a user.
     * Pass null to disable TOTP.
     */
    public function saveTotpSecret(string $userId, ?string $secret): void;

    /**
     * Records the current timestamp as the user's last successful login.
     */
    public function updateLastLoginAt(string $userId): void;

    /**
     * Persists a new user record.
     *
     * $passwordHash MUST be the output of {@see password_hash()} — plain-text
     * passwords must never reach this method.
     */
    public function create(string $id, string $email, string $passwordHash): void;

    /**
     * Replaces the stored password hash for the given user.
     *
     * $passwordHash MUST be the output of {@see password_hash()}.
     */
    public function updatePasswordHash(string $userId, string $passwordHash): void;

    /**
     * Updates the display name for the given user.
     * Pass an empty string to clear the display name.
     */
    public function updateDisplayName(string $userId, string $displayName): void;

    /**
     * Atomically replaces the full set of roles assigned to a user.
     * All roles not present in $roleIds are unassigned.
     *
     * @param list<string> $roleIds
     */
    public function syncRoles(string $userId, array $roleIds): void;

    /** @return list<User> */
    public function findAll(): array;

    public function delete(string $userId): void;
}

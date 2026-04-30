<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Repository;

use TowerDNS\Domain\Auth\PasswordResetToken;

interface PasswordResetTokenRepositoryInterface
{
    /**
     * Stores a new password-reset token (as SHA-256 hash of the raw token).
     * Only the hash is persisted — the raw token is kept by the caller and
     * sent to the user via email.
     */
    public function create(string $userId, string $tokenHash, string $expiresAt): void;

    /**
     * Looks up a token by its SHA-256 hash.
     * Returns null when no matching (unused, non-expired) record exists.
     */
    public function findByHash(string $tokenHash): ?PasswordResetToken;

    /**
     * Marks the token with the given ID as used at the given timestamp.
     */
    public function markUsed(int $id, string $usedAt): void;
}

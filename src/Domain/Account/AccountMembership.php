<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Domain\Account;

/**
 * Represents a user's membership in an Account with a specific TeamRole.
 */
final readonly class AccountMembership
{
    public function __construct(
        public int      $id,
        public int      $accountId,
        public string   $userId,
        public TeamRole $role,
        public string   $createdAt,
        public ?string  $invitedByUserId = null,
    ) {}
}

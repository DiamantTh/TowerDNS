<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Domain\Account;

/**
 * Account / Tenant — the ownership boundary for zones and provider credentials.
 *
 * Every Zone and every ProviderAccount belongs to exactly one Account.
 * Users are associated with Accounts via {@see AccountMembership}.
 */
final readonly class Account
{
    public function __construct(
        public int    $id,
        public string $name,
        public string $slug,
        public string $ownerUserId,
        public bool   $isActive,
        public string $createdAt,
    ) {}
}

<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Domain\Account;

/**
 * Zone-level membership granting a user access to a single zone
 * without giving them account-wide access.
 *
 * Example: Freund A gets TeamRole::DNS_MANAGER on pokeirc.tld only,
 * without seeing diamantthomy.info or sandkaufen.net in the same account.
 */
final readonly class ZoneMembership
{
    public function __construct(
        public int      $id,
        public string   $zoneId,
        public string   $userId,
        public TeamRole $role,
        public string   $createdAt,
        public ?string  $grantedByUserId = null,
    ) {}
}

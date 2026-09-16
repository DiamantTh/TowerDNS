<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Repository;

use TowerDNS\Domain\Account\TeamRole;
use TowerDNS\Domain\Account\ZoneMembership;

interface ZoneMembershipRepositoryInterface
{
    /**
     * Returns all zone-level memberships for a given zone.
     * @return list<ZoneMembership>
     */
    public function findByManagedZoneId(int $managedZoneId): array;

    /**
     * Returns all zone-level memberships for a given user across all zones.
     * Used by PermissionService to determine which zones a user may see.
     * @return list<ZoneMembership>
     */
    public function findByUserId(string $userId): array;

    public function findMembership(int $managedZoneId, string $userId): ?ZoneMembership;

    public function grant(int $managedZoneId, string $userId, TeamRole $role, string $createdAt, ?string $grantedBy = null): void;

    public function updateRole(int $managedZoneId, string $userId, TeamRole $role): void;

    public function revoke(int $managedZoneId, string $userId): void;
}

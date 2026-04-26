<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Services;

use TowerDNS\Application\Exception\AuthorizationException;
use TowerDNS\Application\Repository\AccountRepositoryInterface;
use TowerDNS\Application\Repository\ZoneMembershipRepositoryInterface;
use TowerDNS\Domain\Account\TeamRole;
use TowerDNS\Domain\Auth\Permission;
use TowerDNS\Domain\Auth\User;

/**
 * Account- and zone-level permission checks.
 *
 * This is SEPARATE from {@see AuthorizationService}, which handles system-wide
 * panel permissions (IAM, system settings, etc.).
 *
 * PermissionService governs what a user may do within a specific Account or Zone:
 *   - Access is granted via account_memberships (account-wide role)
 *   - OR via zone_memberships (zone-specific role, without account-wide access)
 *   - System admins with Permission::USER_MANAGE bypass account checks entirely
 *     (this is the Admin-Switch path — must be audited separately)
 */
final class PermissionService
{
    public function __construct(
        private readonly AccountRepositoryInterface       $accounts,
        private readonly ZoneMembershipRepositoryInterface $zoneMemberships,
    ) {}

    // ── Account-level checks ──────────────────────────────────────────────────

    /**
     * Returns the effective TeamRole for a user in an account.
     * Returns null if the user has no membership.
     */
    public function getAccountRole(int $accountId, User $user): ?TeamRole
    {
        // System admin bypasses membership check
        if ($user->hasPermission(Permission::USER_MANAGE)) {
            return TeamRole::OWNER;
        }

        return $this->accounts->getEffectiveRole($accountId, $user->id);
    }

    public function canViewAccount(int $accountId, User $user): bool
    {
        return $this->getAccountRole($accountId, $user) !== null;
    }

    public function canManageAccount(int $accountId, User $user): bool
    {
        $role = $this->getAccountRole($accountId, $user);
        return $role !== null && $role->canManageAccount();
    }

    public function canManageMembers(int $accountId, User $user): bool
    {
        $role = $this->getAccountRole($accountId, $user);
        return $role !== null && $role->canManageMembers();
    }

    public function canManageProviderAccounts(int $accountId, User $user): bool
    {
        $role = $this->getAccountRole($accountId, $user);
        return $role !== null && $role->canManageProviderAccounts();
    }

    public function canDeleteAccount(int $accountId, User $user): bool
    {
        if ($user->hasPermission(Permission::USER_MANAGE)) {
            return true;
        }
        $role = $this->getAccountRole($accountId, $user);
        return $role !== null && $role->canDeleteAccount();
    }

    public function canViewAuditLog(int $accountId, User $user): bool
    {
        if ($user->hasPermission(Permission::USER_MANAGE)) {
            return true;
        }
        $role = $this->getAccountRole($accountId, $user);
        return $role !== null && $role->canViewAuditLog();
    }

    // ── Zone-level checks ─────────────────────────────────────────────────────

    /**
     * Returns the effective TeamRole for a user on a zone.
     *
     * Resolution order:
     *   1. System admin → synthesised OWNER
     *   2. Account-level role (account_memberships)
     *   3. Zone-level role (zone_memberships)
     *   4. null → no access
     */
    public function getZoneRole(string $zoneId, int $accountId, User $user): ?TeamRole
    {
        // System admin
        if ($user->hasPermission(Permission::USER_MANAGE)) {
            return TeamRole::OWNER;
        }

        // Account-level role supersedes zone-level role
        $accountRole = $this->accounts->getEffectiveRole($accountId, $user->id);
        if ($accountRole !== null) {
            return $accountRole;
        }

        // Zone-specific membership
        $zm = $this->zoneMemberships->findMembership($zoneId, $user->id);
        return $zm?->role;
    }

    public function canViewZone(string $zoneId, int $accountId, User $user): bool
    {
        $role = $this->getZoneRole($zoneId, $accountId, $user);
        return $role !== null && $role->canViewZone();
    }

    public function canManageZoneRecords(string $zoneId, int $accountId, User $user): bool
    {
        $role = $this->getZoneRole($zoneId, $accountId, $user);
        return $role !== null && $role->canManageZoneRecords();
    }

    // ── Impersonation ─────────────────────────────────────────────────────────

    /**
     * Only users with USER_MANAGE (system admin) may initiate an Admin-Switch.
     */
    public function canImpersonate(User $actor): bool
    {
        return $actor->hasPermission(Permission::USER_MANAGE);
    }

    // ── Assertion helpers ─────────────────────────────────────────────────────

    public function assertCanManageZoneRecords(string $zoneId, int $accountId, User $user): void
    {
        if (!$this->canManageZoneRecords($zoneId, $accountId, $user)) {
            throw new AuthorizationException(
                sprintf('Kein Recht für DNS-Verwaltung in Zone %s.', $zoneId)
            );
        }
    }

    public function assertCanViewZone(string $zoneId, int $accountId, User $user): void
    {
        if (!$this->canViewZone($zoneId, $accountId, $user)) {
            throw new AuthorizationException(
                sprintf('Kein Zugriff auf Zone %s.', $zoneId)
            );
        }
    }

    public function assertCanManageMembers(int $accountId, User $user): void
    {
        if (!$this->canManageMembers($accountId, $user)) {
            throw new AuthorizationException(
                sprintf('Kein Recht zur Mitgliederverwaltung in Account %d.', $accountId)
            );
        }
    }

    public function assertCanManageAccount(int $accountId, User $user): void
    {
        if (!$this->canManageAccount($accountId, $user)) {
            throw new AuthorizationException(
                sprintf('Kein Recht zur Verwaltung von Account %d.', $accountId)
            );
        }
    }

    public function assertCanManageProviderAccounts(int $accountId, User $user): void
    {
        if (!$this->canManageProviderAccounts($accountId, $user)) {
            throw new AuthorizationException(
                sprintf('Kein Recht für ProviderAccount-Verwaltung in Account %d.', $accountId)
            );
        }
    }

    public function assertCanViewAccount(int $accountId, User $user): void
    {
        if (!$this->canViewAccount($accountId, $user)) {
            throw new AuthorizationException(
                sprintf('Kein Zugriff auf Account %d.', $accountId)
            );
        }
    }

    public function assertCanImpersonate(User $actor): void
    {
        if (!$this->canImpersonate($actor)) {
            throw new AuthorizationException('Nur Systemadmins dürfen den Admin-Switch verwenden.');
        }
    }
}

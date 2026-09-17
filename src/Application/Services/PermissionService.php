<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Services;

use TowerDNS\Application\Exception\AuthorizationException;
use TowerDNS\Application\Repository\AccountRepositoryInterface;
use TowerDNS\Application\Repository\ManagedZoneRepositoryInterface;
use TowerDNS\Application\Repository\ZoneMembershipRepositoryInterface;
use TowerDNS\Domain\Account\TeamRole;
use TowerDNS\Domain\Auth\Permission;
use TowerDNS\Domain\Auth\User;

/**
 * Resolves membership-derived scopes and delegates role permission checks to
 * Laminas RBAC through {@see RbacPermissionChecker}.
 *
 * System roles, account memberships and zone memberships are deliberately
 * separate grant sources. There are no deny rules or object overrides.
 */
final readonly class PermissionService
{
    private AuthorizationService $authorization;
    private RbacPermissionChecker $rbac;

    public function __construct(
        private AccountRepositoryInterface $accounts,
        private ZoneMembershipRepositoryInterface $zoneMemberships,
        ?AuthorizationService $authorization = null,
        ?RbacPermissionChecker $rbac = null,
        private ?ManagedZoneRepositoryInterface $managedZones = null,
    ) {
        $this->rbac          = $rbac          ?? new RbacPermissionChecker();
        $this->authorization = $authorization ?? new AuthorizationService($this->rbac);
    }

    public function authorizeSystem(User $user, Permission $permission): bool
    {
        return $this->authorization->isGranted($user, $permission);
    }

    public function authorizeAccount(User $user, Permission $permission, int $accountId): bool
    {
        if ($this->authorization->isGranted($user, Permission::SYSTEM_ACCOUNTS_ACCESS)) {
            return true;
        }

        $role = $this->accounts->getEffectiveRole($accountId, $user->id);

        return $role instanceof TeamRole && $this->roleGrants($role, $permission);
    }

    public function authorizeZone(User $user, Permission $permission, int $accountId, string $zoneId): bool
    {
        return ctype_digit($zoneId)
            && $this->authorizeManagedZone($user, $permission, $accountId, (int) $zoneId);
    }

    /** Authorize against TowerDNS's internal ManagedZone identity. */
    public function authorizeManagedZone(User $user, Permission $permission, int $accountId, int $managedZoneId): bool
    {
        $managedZone = $this->managedZones?->findByIdForAccount($managedZoneId, $accountId);
        if (!$managedZone instanceof \TowerDNS\Domain\Account\ManagedZone) {
            return false;
        }

        if ($this->authorization->isGranted($user, Permission::SYSTEM_ACCOUNTS_ACCESS)) {
            return true;
        }

        $accountRole = $this->accounts->getEffectiveRole($accountId, $user->id);
        if ($accountRole instanceof TeamRole && $this->roleGrants($accountRole, $permission)) {
            return true;
        }

        $membership = $this->zoneMemberships->findMembership($managedZone->id, $user->id);
        return $membership instanceof \TowerDNS\Domain\Account\ZoneMembership && $this->roleGrants($membership->role, $permission);
    }

    /**
     * Returns the direct account role, if any. It intentionally does not turn
     * a global system permission into an account role.
     */
    public function getAccountRole(int $accountId, User $user): ?TeamRole
    {
        return $this->accounts->getEffectiveRole($accountId, $user->id);
    }

    /**
     * Kept for read-only display code. Authorization must use authorizeManagedZone(),
     * because account and zone grants can positively complement each other.
     */
    public function getZoneRole(string $zoneId, int $accountId, User $user): ?TeamRole
    {
        if (!ctype_digit($zoneId) || !$this->managedZones?->findByIdForAccount((int) $zoneId, $accountId) instanceof \TowerDNS\Domain\Account\ManagedZone) {
            return null;
        }

        $accountRole = $this->getAccountRole($accountId, $user);
        if ($accountRole instanceof TeamRole) {
            return $accountRole;
        }

        return $this->zoneMemberships->findMembership((int) $zoneId, $user->id)?->role;
    }

    // ── Temporary application conveniences ──────────────────────────────────

    public function canViewAccount(int $accountId, User $user): bool
    {
        return $this->authorizeAccount($user, Permission::ACCOUNT_READ, $accountId);
    }

    public function canManageAccount(int $accountId, User $user): bool
    {
        return $this->authorizeAccount($user, Permission::ACCOUNT_UPDATE, $accountId);
    }

    public function canManageMembers(int $accountId, User $user): bool
    {
        return $this->authorizeAccount($user, Permission::ACCOUNT_MEMBERS_MANAGE, $accountId);
    }

    public function canManageProviderAccounts(int $accountId, User $user): bool
    {
        return $this->authorizeAccount($user, Permission::PROVIDER_CREDENTIALS_MANAGE, $accountId);
    }

    public function canDeleteAccount(int $accountId, User $user): bool
    {
        return $this->authorizeAccount($user, Permission::ACCOUNT_DELETE, $accountId);
    }

    public function canViewAuditLog(int $accountId, User $user): bool
    {
        return $this->authorizeAccount($user, Permission::AUDIT_READ, $accountId);
    }

    public function canViewZone(string $zoneId, int $accountId, User $user): bool
    {
        return $this->authorizeZone($user, Permission::ZONE_READ, $accountId, $zoneId);
    }

    public function canManageZoneRecords(string $zoneId, int $accountId, User $user): bool
    {
        return $this->authorizeZone($user, Permission::RECORD_UPDATE, $accountId, $zoneId);
    }

    public function canImpersonate(User $actor): bool
    {
        return $this->authorizeSystem($actor, Permission::SYSTEM_IMPERSONATION_EXECUTE);
    }

    // ── Assertions ──────────────────────────────────────────────────────────

    public function assertCanManageZoneRecords(string $zoneId, int $accountId, User $user): void
    {
        $this->assertZone($user, Permission::RECORD_UPDATE, $accountId, $zoneId);
    }

    public function assertCanViewZone(string $zoneId, int $accountId, User $user): void
    {
        $this->assertZone($user, Permission::ZONE_READ, $accountId, $zoneId);
    }

    public function assertCanManageMembers(int $accountId, User $user): void
    {
        $this->assertAccount($user, Permission::ACCOUNT_MEMBERS_MANAGE, $accountId);
    }

    public function assertCanTransferAccountOwnership(int $accountId, User $user): void
    {
        $this->assertAccount($user, Permission::ACCOUNT_OWNERSHIP_TRANSFER, $accountId);
    }

    public function assertCanManageAccount(int $accountId, User $user): void
    {
        $this->assertAccount($user, Permission::ACCOUNT_UPDATE, $accountId);
    }

    public function assertCanManageProviderAccounts(int $accountId, User $user): void
    {
        $this->assertAccount($user, Permission::PROVIDER_CREDENTIALS_MANAGE, $accountId);
    }

    public function assertCanViewAccount(int $accountId, User $user): void
    {
        $this->assertAccount($user, Permission::ACCOUNT_READ, $accountId);
    }

    public function assertCanImpersonate(User $actor): void
    {
        if (!$this->canImpersonate($actor)) {
            throw new AuthorizationException('Kein Recht für Admin-Switch.');
        }
    }

    public function assertAccount(User $user, Permission $permission, int $accountId): void
    {
        if (!$this->authorizeAccount($user, $permission, $accountId)) {
            throw new AuthorizationException(sprintf('Kein Recht "%s" in Account %d.', $permission->value, $accountId));
        }
    }

    public function assertZone(User $user, Permission $permission, int $accountId, string $zoneId): void
    {
        if (!$this->authorizeZone($user, $permission, $accountId, $zoneId)) {
            throw new AuthorizationException(sprintf('Kein Recht "%s" in Zone %s.', $permission->value, $zoneId));
        }
    }

    private function roleGrants(TeamRole $role, Permission $permission): bool
    {
        return $this->rbac->isGranted($role->asRole(), $permission->value);
    }
}

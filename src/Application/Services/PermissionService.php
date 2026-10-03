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
        $account = $this->accounts->findById($accountId);
        if (!$user->active || !$account instanceof \TowerDNS\Domain\Account\Account || !$account->isActive) {
            return false;
        }

        if ($this->authorization->isBuiltInSuperadmin($user)) {
            return true;
        }

        if ($this->authorization->isGranted($user, Permission::SYSTEM_ACCOUNTS_ACCESS)
            && in_array($permission, [
                Permission::ACCOUNT_READ,
                Permission::ZONE_LIST,
                Permission::ZONE_READ,
                Permission::RECORD_READ,
                Permission::DNSSEC_STATUS_READ,
            ], true)) {
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
        $account     = $this->accounts->findById($accountId);
        $managedZone = $this->managedZones?->findByIdForAccount($managedZoneId, $accountId);
        if (!$user->active || !$account instanceof \TowerDNS\Domain\Account\Account || !$account->isActive
                           || !$managedZone instanceof \TowerDNS\Domain\Account\ManagedZone) {
            return false;
        }

        if ($this->authorization->isBuiltInSuperadmin($user)) {
            return true;
        }

        if ($this->authorization->isGranted($user, Permission::SYSTEM_ACCOUNTS_ACCESS)
            && in_array($permission, [
                Permission::ACCOUNT_READ,
                Permission::ZONE_LIST,
                Permission::ZONE_READ,
                Permission::RECORD_READ,
                Permission::DNSSEC_STATUS_READ,
            ], true)) {
            return true;
        }

        $accountRole = $this->accounts->getEffectiveRole($accountId, $user->id);
        if ($accountRole instanceof TeamRole && $this->roleGrants($accountRole, $permission)) {
            return true;
        }

        $membership = $this->zoneMemberships->findMembership($managedZone->id, $user->id);
        return $membership instanceof \TowerDNS\Domain\Account\ZoneMembership && $this->roleGrants($membership->role, $permission);
    }

    // ── Explicit lifecycle checks ───────────────────────────────────────────
    /** Reactivation is the one management action that must remain possible for a disabled organization. */
    public function canReactivateAccount(int $accountId, User $user): bool
    {
        $account = $this->accounts->findById($accountId);
        if (!$user->active || !$account instanceof \TowerDNS\Domain\Account\Account) {
            return false;
        }
        if ($this->authorization->isBuiltInSuperadmin($user)) {
            return true;
        }
        $role = $this->accounts->getEffectiveRole($accountId, $user->id);
        return $role instanceof TeamRole && $this->roleGrants($role, Permission::ACCOUNT_UPDATE);
    }

    public function assertCanReactivateAccount(int $accountId, User $user): void
    {
        if (!$this->canReactivateAccount($accountId, $user)) {
            throw new AuthorizationException(sprintf('Kein Recht zum Reaktivieren von Account %d.', $accountId));
        }
    }

    public function canImpersonate(User $actor): bool
    {
        return $actor->active && $this->authorization->isBuiltInSuperadmin($actor);
    }

    // ── Assertions ──────────────────────────────────────────────────────────

    public function assertCanManageMembers(int $accountId, User $user): void
    {
        $this->assertAccount($user, Permission::ACCOUNT_MEMBERS_MANAGE, $accountId);
    }

    public function assertCanManageAccount(int $accountId, User $user): void
    {
        $this->assertAccount($user, Permission::ACCOUNT_UPDATE, $accountId);
    }

    public function assertCanManageProviderAccounts(int $accountId, User $user): void
    {
        $this->assertAccount($user, Permission::PROVIDER_CREDENTIALS_MANAGE, $accountId);
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

    public function assertAccountActive(int $accountId): void
    {
        $account = $this->accounts->findById($accountId);
        if (!$account instanceof \TowerDNS\Domain\Account\Account || !$account->isActive) {
            throw new \DomainException('Account is inactive.');
        }
    }

    private function roleGrants(TeamRole $role, Permission $permission): bool
    {
        return $this->rbac->isGranted($role->asRole(), $permission->value);
    }
}

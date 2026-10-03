<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Application\Services;

use PHPUnit\Framework\TestCase;
use TowerDNS\Application\Repository\AccountRepositoryInterface;
use TowerDNS\Application\Repository\ManagedZoneRepositoryInterface;
use TowerDNS\Application\Repository\ZoneMembershipRepositoryInterface;
use TowerDNS\Application\Services\AuthorizationService;
use TowerDNS\Application\Services\PermissionService;
use TowerDNS\Application\Services\RbacPermissionChecker;
use TowerDNS\Domain\Account\Account;
use TowerDNS\Domain\Account\AccountKind;
use TowerDNS\Domain\Account\TeamRole;
use TowerDNS\Domain\Account\ZoneMembership;
use TowerDNS\Domain\Auth\Permission;
use TowerDNS\Domain\Auth\Role;
use TowerDNS\Domain\Auth\User;

/** @psalm-api Runtime discovery by PHPUnit or local module loading is not statically visible. */
final class PermissionServiceTest extends TestCase
{
    public function testTeamRoleBundlesExpressTheExpectedBoundaries(): void
    {
        self::assertContains(Permission::ACCOUNT_DELETE, TeamRole::OWNER->permissions());
        self::assertNotContains(Permission::ACCOUNT_DELETE, TeamRole::ADMIN->permissions());

        self::assertContains(Permission::RECORD_UPDATE, TeamRole::DNS_MANAGER->permissions());
        self::assertNotContains(Permission::ACCOUNT_MEMBERS_MANAGE, TeamRole::DNS_MANAGER->permissions());
        self::assertNotContains(Permission::ACCOUNT_OWNERSHIP_TRANSFER, TeamRole::DNS_MANAGER->permissions());

        self::assertContains(Permission::RECORD_READ, TeamRole::VIEWER->permissions());
        self::assertNotContains(Permission::RECORD_CREATE, TeamRole::VIEWER->permissions());

        self::assertContains(Permission::AUDIT_READ, TeamRole::AUDITOR->permissions());
        self::assertNotContains(Permission::RECORD_UPDATE, TeamRole::AUDITOR->permissions());
    }

    public function testAccountMembershipGrantsItsPermissionBundleToTheAccountAndZones(): void
    {
        $service = $this->serviceFor(TeamRole::DNS_MANAGER);
        $user    = new User('user-1', 'user@example.test');

        self::assertTrue($service->authorizeAccount($user, Permission::RECORD_UPDATE, 42));
        self::assertTrue($service->authorizeManagedZone($user, Permission::RECORD_UPDATE, 42, 1));
        self::assertFalse($service->authorizeAccount($user, Permission::ACCOUNT_MEMBERS_MANAGE, 42));
        self::assertFalse($service->authorizeAccount($user, Permission::RECORD_UPDATE, 43));
    }

    public function testZoneMembershipOnlyGrantsTheReferencedZone(): void
    {
        $zoneMembership = new ZoneMembership(
            id: 1,
            managedZoneId: 1,
            userId: 'user-1',
            role: TeamRole::DNS_MANAGER,
            createdAt: '2026-09-13 12:00:00',
        );
        $service = $this->serviceFor(null, $zoneMembership);
        $user    = new User('user-1', 'user@example.test');

        self::assertTrue($service->authorizeManagedZone($user, Permission::RECORD_UPDATE, 42, 1));
        self::assertFalse($service->authorizeManagedZone($user, Permission::RECORD_UPDATE, 42, 2));
        self::assertFalse($service->authorizeAccount($user, Permission::RECORD_UPDATE, 42));
    }

    public function testLegacyZoneAuthorizationStillRequiresManagedZoneAccountIsolation(): void
    {
        $zoneMembership = new ZoneMembership(1, 1, 'user-1', TeamRole::DNS_MANAGER, '2026-09-13 12:00:00');
        $service        = $this->serviceFor(null, $zoneMembership);
        $user           = new User('user-1', 'user@example.test');

        self::assertTrue($service->authorizeZone($user, Permission::RECORD_UPDATE, 42, '1'));
        self::assertFalse($service->authorizeZone($user, Permission::RECORD_UPDATE, 43, '1'));
    }

    public function testAccountAndZoneGrantsCanComplementEachOtherWithoutOverrides(): void
    {
        $zoneMembership = new ZoneMembership(
            id: 1,
            managedZoneId: 1,
            userId: 'user-1',
            role: TeamRole::DNS_MANAGER,
            createdAt: '2026-09-13 12:00:00',
        );
        $service = $this->serviceFor(TeamRole::VIEWER, $zoneMembership);
        $user    = new User('user-1', 'user@example.test');

        self::assertTrue($service->authorizeManagedZone($user, Permission::RECORD_READ, 42, 1));
        self::assertTrue($service->authorizeManagedZone($user, Permission::RECORD_UPDATE, 42, 1));
        self::assertFalse($service->authorizeManagedZone($user, Permission::RECORD_UPDATE, 42, 2));
    }

    public function testSystemUserManagementDoesNotGrantAccountAccess(): void
    {
        $service = $this->serviceFor();
        $user    = new User('user-1', 'user@example.test', [
            new Role('iam', 'IAM administrator', [Permission::USER_MANAGE]),
        ]);

        self::assertTrue($service->authorizeSystem($user, Permission::USER_MANAGE));
        self::assertFalse($service->authorizeAccount($user, Permission::ACCOUNT_READ, 42));
        self::assertFalse($service->authorizeManagedZone($user, Permission::RECORD_UPDATE, 42, 1));
    }

    public function testExplicitSystemAccountAccessIsReadOnlyAndBoundToExistingResources(): void
    {
        $service = $this->serviceFor();
        $user    = new User('operator-1', 'operator@example.test', [
            new Role('operator', 'System operator', [Permission::SYSTEM_ACCOUNTS_ACCESS]),
        ]);

        self::assertTrue($service->authorizeAccount($user, Permission::ACCOUNT_READ, 42));
        self::assertTrue($service->authorizeManagedZone($user, Permission::RECORD_READ, 42, 1));
        self::assertFalse($service->authorizeManagedZone($user, Permission::RECORD_UPDATE, 42, 1));
        self::assertFalse($service->authorizeManagedZone($user, Permission::RECORD_UPDATE, 42, 999));
    }

    public function testOnlyTheBuiltInSuperadminCanImpersonate(): void
    {
        $service = $this->serviceFor();

        self::assertFalse($service->canImpersonate(new User('iam-1', 'iam@example.test', [
            new Role('iam', 'IAM administrator', [Permission::USER_MANAGE]),
        ])));
        self::assertFalse($service->canImpersonate(new User('operator-1', 'operator@example.test', [
            new Role('operator', 'System operator', [Permission::SYSTEM_IMPERSONATION_EXECUTE]),
        ])));
        self::assertFalse($service->canImpersonate(new User('fake-su', 'fake@example.test', [
            new Role('superadmin', 'Untrusted custom role', [Permission::SYSTEM_IMPERSONATION_EXECUTE]),
        ])));
        self::assertTrue($service->canImpersonate(new User('root-1', 'root@example.test', [
            new Role('superadmin', 'Superadmin', [], isBuiltIn: true),
        ])));
    }

    private function serviceFor(?TeamRole $accountRole = null, ?ZoneMembership $zoneMembership = null): PermissionService
    {
        $accounts = $this->createMock(AccountRepositoryInterface::class);
        $accounts->method('findById')->willReturnCallback(
            static fn(int $accountId): ?Account => in_array($accountId, [42, 43], true)
                ? new Account($accountId, 'Test', 'test-' . $accountId, 'user-1', true, '2026-09-13 12:00:00', AccountKind::ORGANIZATION)
                : null,
        );
        $accounts->method('getEffectiveRole')->willReturnCallback(
            static fn(int $accountId, string $userId): ?TeamRole => $accountId === 42 && $userId === 'user-1'
                ? $accountRole
                : null,
        );

        $zones = $this->createMock(ZoneMembershipRepositoryInterface::class);
        $zones->method('findMembership')->willReturnCallback(
            static fn(int $managedZoneId, string $userId): ?ZoneMembership => $managedZoneId === 1 && $userId === 'user-1'
                ? $zoneMembership
                : null,
        );

        $managedZones = $this->createMock(ManagedZoneRepositoryInterface::class);
        $managedZones->method('findByIdForAccount')->willReturnCallback(
            static fn(int $id, int $accountId): ?\TowerDNS\Domain\Account\ManagedZone => $accountId === 42 && $id === 1
                ? new \TowerDNS\Domain\Account\ManagedZone($id, 42, 7, 'external-' . $id, 'example.test', '2026-09-13 12:00:00')
                : null,
        );

        $rbac = new RbacPermissionChecker();

        return new PermissionService($accounts, $zones, new AuthorizationService($rbac), $rbac, $managedZones);
    }
}

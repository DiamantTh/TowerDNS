<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Application\Services;

use PHPUnit\Framework\TestCase;
use TowerDNS\Application\Repository\AccountRepositoryInterface;
use TowerDNS\Application\Repository\ZoneMembershipRepositoryInterface;
use TowerDNS\Application\Services\AuthorizationService;
use TowerDNS\Application\Services\PermissionService;
use TowerDNS\Application\Services\RbacPermissionChecker;
use TowerDNS\Domain\Account\TeamRole;
use TowerDNS\Domain\Account\ZoneMembership;
use TowerDNS\Domain\Auth\Permission;
use TowerDNS\Domain\Auth\Role;
use TowerDNS\Domain\Auth\User;

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
        self::assertTrue($service->authorizeZone($user, Permission::RECORD_UPDATE, 42, 'zone-a'));
        self::assertFalse($service->authorizeAccount($user, Permission::ACCOUNT_MEMBERS_MANAGE, 42));
        self::assertFalse($service->authorizeAccount($user, Permission::RECORD_UPDATE, 43));
    }

    public function testZoneMembershipOnlyGrantsTheReferencedZone(): void
    {
        $zoneMembership = new ZoneMembership(
            id: 1,
            zoneId: 'zone-a',
            userId: 'user-1',
            role: TeamRole::DNS_MANAGER,
            createdAt: '2026-09-13 12:00:00',
        );
        $service = $this->serviceFor(null, $zoneMembership);
        $user    = new User('user-1', 'user@example.test');

        self::assertTrue($service->authorizeZone($user, Permission::RECORD_UPDATE, 42, 'zone-a'));
        self::assertFalse($service->authorizeZone($user, Permission::RECORD_UPDATE, 42, 'zone-b'));
        self::assertFalse($service->authorizeAccount($user, Permission::RECORD_UPDATE, 42));
    }

    public function testAccountAndZoneGrantsCanComplementEachOtherWithoutOverrides(): void
    {
        $zoneMembership = new ZoneMembership(
            id: 1,
            zoneId: 'zone-a',
            userId: 'user-1',
            role: TeamRole::DNS_MANAGER,
            createdAt: '2026-09-13 12:00:00',
        );
        $service = $this->serviceFor(TeamRole::VIEWER, $zoneMembership);
        $user    = new User('user-1', 'user@example.test');

        self::assertTrue($service->authorizeZone($user, Permission::RECORD_READ, 42, 'zone-a'));
        self::assertTrue($service->authorizeZone($user, Permission::RECORD_UPDATE, 42, 'zone-a'));
        self::assertFalse($service->authorizeZone($user, Permission::RECORD_UPDATE, 42, 'zone-b'));
    }

    public function testSystemUserManagementDoesNotGrantAccountAccess(): void
    {
        $service = $this->serviceFor();
        $user    = new User('user-1', 'user@example.test', [
            new Role('iam', 'IAM administrator', [Permission::USER_MANAGE]),
        ]);

        self::assertTrue($service->authorizeSystem($user, Permission::USER_MANAGE));
        self::assertFalse($service->authorizeAccount($user, Permission::ACCOUNT_READ, 42));
        self::assertFalse($service->authorizeZone($user, Permission::RECORD_UPDATE, 42, 'zone-a'));
    }

    public function testExplicitSystemAccountAccessGrantsGlobalAccountAndZoneAccess(): void
    {
        $service = $this->serviceFor();
        $user    = new User('operator-1', 'operator@example.test', [
            new Role('operator', 'System operator', [Permission::SYSTEM_ACCOUNTS_ACCESS]),
        ]);

        self::assertTrue($service->authorizeAccount($user, Permission::ACCOUNT_READ, 42));
        self::assertTrue($service->authorizeZone($user, Permission::RECORD_UPDATE, 42, 'zone-a'));
    }

    public function testImpersonationRequiresItsOwnSystemPermission(): void
    {
        $service = $this->serviceFor();

        self::assertFalse($service->canImpersonate(new User('iam-1', 'iam@example.test', [
            new Role('iam', 'IAM administrator', [Permission::USER_MANAGE]),
        ])));
        self::assertTrue($service->canImpersonate(new User('operator-1', 'operator@example.test', [
            new Role('operator', 'System operator', [Permission::SYSTEM_IMPERSONATION_EXECUTE]),
        ])));
    }

    private function serviceFor(?TeamRole $accountRole = null, ?ZoneMembership $zoneMembership = null): PermissionService
    {
        $accounts = $this->createMock(AccountRepositoryInterface::class);
        $accounts->method('getEffectiveRole')->willReturnCallback(
            static fn(int $accountId, string $userId): ?TeamRole => $accountId === 42 && $userId === 'user-1'
                ? $accountRole
                : null,
        );

        $zones = $this->createMock(ZoneMembershipRepositoryInterface::class);
        $zones->method('findMembership')->willReturnCallback(
            static fn(string $zoneId, string $userId): ?ZoneMembership => $zoneId === 'zone-a' && $userId === 'user-1'
                ? $zoneMembership
                : null,
        );

        $rbac = new RbacPermissionChecker();

        return new PermissionService($accounts, $zones, new AuthorizationService($rbac), $rbac);
    }
}

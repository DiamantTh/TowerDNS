<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Application\Services;

use PHPUnit\Framework\TestCase;
use TowerDNS\Application\Repository\AccountRepositoryInterface;
use TowerDNS\Application\Repository\UserRepositoryInterface;
use TowerDNS\Application\Repository\ZoneMembershipRepositoryInterface;
use TowerDNS\Application\Services\AccountMembershipManagementService;
use TowerDNS\Application\Services\AuthorizationService;
use TowerDNS\Application\Services\PermissionService;
use TowerDNS\Application\Services\RbacPermissionChecker;
use TowerDNS\Application\Services\ResourceLimitService;
use TowerDNS\Domain\Account\Account;
use TowerDNS\Domain\Account\AccountMembership;
use TowerDNS\Domain\Account\TeamRole;
use TowerDNS\Domain\Auth\User;

final class AccountMembershipManagementServiceTest extends TestCase
{
    public function testInviteChecksQuotaAndCreatesMembership(): void
    {
        /** @var \PHPUnit\Framework\MockObject\MockObject&AccountRepositoryInterface $accounts */
        $accounts = $this->createMock(AccountRepositoryInterface::class);
        $accounts->method('findById')->with(42)->willReturn(new Account(42, 'Team', 'team', 'owner', true, '2026-09-16 00:00:00'));
        $accounts->method('findMembership')->with(42, 'target')->willReturn(null);
        $accounts->method('findMemberships')->with(42)->willReturn([]);
        $accounts->expects(self::once())->method('addMembership')->with(
            42,
            'target',
            TeamRole::ADMIN,
            self::isType('string'),
            'actor',
        );

        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findById')->with('target')->willReturn(new User('target', 'target@example.test'));

        $limitsRepository = $this->createMock(\TowerDNS\Application\Repository\AccountResourceLimitsRepositoryInterface::class);
        $limitsRepository->method('findByAccountId')->with(42)->willReturn(new \TowerDNS\Domain\Account\AccountResourceLimits(42, 3, 3, 3));
        $managedZones = $this->createMock(\TowerDNS\Application\Repository\ManagedZoneRepositoryInterface::class);
        $managedZones->method('findByAccountId')->with(42)->willReturn([]);
        $providerAccounts = $this->createMock(\TowerDNS\Application\Repository\ProviderAccountRepositoryInterface::class);
        $providerAccounts->method('findByAccountId')->with(42)->willReturn([]);
        $limits = new ResourceLimitService($limitsRepository, $managedZones, $accounts, $providerAccounts);

        $permissions = $this->servicePermissions($accounts);
        $service     = new AccountMembershipManagementService($accounts, $users, $permissions, $limits);

        $service->invite(new User('actor', 'actor@example.test'), 42, 'target', TeamRole::ADMIN);
    }

    public function testRevokeRejectsOwnerTransferBypass(): void
    {
        /** @var \PHPUnit\Framework\MockObject\MockObject&AccountRepositoryInterface $accounts */
        $accounts = $this->createMock(AccountRepositoryInterface::class);
        $accounts->method('findById')->with(42)->willReturn(new Account(42, 'Team', 'team', 'owner', true, '2026-09-16 00:00:00'));
        $accounts->method('findMembership')->with(42, 'owner')->willReturn(new AccountMembership(1, 42, 'owner', TeamRole::OWNER, '2026-09-16 00:00:00'));

        $users       = $this->createMock(UserRepositoryInterface::class);
        $permissions = $this->servicePermissions($accounts);
        $service     = new AccountMembershipManagementService($accounts, $users, $permissions);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Account owners cannot be removed. Transfer ownership first.');
        $service->revoke(new User('actor', 'actor@example.test'), 42, 'owner');
    }

    public function testPersonalAccountRejectsAdditionalMembers(): void
    {
        $accounts = $this->createMock(AccountRepositoryInterface::class);
        $accounts->method('findById')->with(42)->willReturn(new Account(42, 'Personal', 'personal-actor', 'actor', true, '2026-09-16 00:00:00', \TowerDNS\Domain\Account\AccountKind::PERSONAL, 'actor'));
        $accounts->expects(self::never())->method('addMembership');
        $users   = $this->createMock(UserRepositoryInterface::class);
        $service = new AccountMembershipManagementService($accounts, $users, $this->servicePermissions($accounts));
        $this->expectException(\DomainException::class);
        $service->invite(new User('actor', 'actor@example.test'), 42, 'target', TeamRole::VIEWER);
    }

    private function servicePermissions(AccountRepositoryInterface $accounts): PermissionService
    {
        /** @var \PHPUnit\Framework\MockObject\MockObject&AccountRepositoryInterface $accounts */
        $rbac        = new RbacPermissionChecker();
        $memberships = $this->createMock(ZoneMembershipRepositoryInterface::class);
        $accounts->method('getEffectiveRole')->with(42, 'actor')->willReturn(TeamRole::ADMIN);
        return new PermissionService($accounts, $memberships, new AuthorizationService($rbac), $rbac);
    }
}

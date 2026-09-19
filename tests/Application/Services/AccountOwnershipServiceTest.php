<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Application\Services;

use PHPUnit\Framework\TestCase;
use TowerDNS\Application\Repository\AccountRepositoryInterface;
use TowerDNS\Application\Repository\UserRepositoryInterface;
use TowerDNS\Application\Repository\ZoneMembershipRepositoryInterface;
use TowerDNS\Application\Services\AccountOwnershipService;
use TowerDNS\Application\Services\AuthorizationService;
use TowerDNS\Application\Services\PermissionService;
use TowerDNS\Application\Services\RbacPermissionChecker;
use TowerDNS\Domain\Account\Account;
use TowerDNS\Domain\Account\AccountMembership;
use TowerDNS\Domain\Account\TeamRole;
use TowerDNS\Domain\Auth\User;

final class AccountOwnershipServiceTest extends TestCase
{
    public function testTransfersOwnershipToAnActiveAccountMember(): void
    {
        /** @var \PHPUnit\Framework\MockObject\MockObject&AccountRepositoryInterface $accounts */
        $accounts = $this->createMock(AccountRepositoryInterface::class);
        $accounts->method('findById')->with(42)->willReturn(new Account(42, 'Team', 'team', 'owner', true, '2026-09-16 00:00:00'));
        $accounts->method('findMembership')->with(42, 'target')->willReturn(new AccountMembership(7, 42, 'target', TeamRole::ADMIN, '2026-09-16 00:00:00', 'owner'));
        $accounts->expects(self::once())->method('transferOwnership')->with(42, 'target');
        $accounts->method('getEffectiveRole')->with(42, 'actor')->willReturn(TeamRole::OWNER);

        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findById')->with('target')->willReturn(new User('target', 'target@example.test'));

        $service = new AccountOwnershipService($accounts, $users, $this->permissionService($accounts));
        $service->transfer(new User('actor', 'actor@example.test'), 42, 'target');
    }

    public function testRejectsAUserWithoutAccountMembership(): void
    {
        /** @var \PHPUnit\Framework\MockObject\MockObject&AccountRepositoryInterface $accounts */
        $accounts = $this->createMock(AccountRepositoryInterface::class);
        $accounts->method('findById')->with(42)->willReturn(new Account(42, 'Team', 'team', 'owner', true, '2026-09-16 00:00:00'));
        $accounts->method('findMembership')->with(42, 'target')->willReturn(null);
        $accounts->method('getEffectiveRole')->with(42, 'actor')->willReturn(TeamRole::OWNER);

        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findById')->with('target')->willReturn(new User('target', 'target@example.test'));

        $service = new AccountOwnershipService($accounts, $users, $this->permissionService($accounts));

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Target user must already be a member of the account.');
        $service->transfer(new User('actor', 'actor@example.test'), 42, 'target');
    }

    public function testPersonalAccountOwnershipCannotBeTransferred(): void
    {
        $accounts = $this->createMock(AccountRepositoryInterface::class);
        $accounts->method('findById')->with(42)->willReturn(new Account(42, 'Personal', 'personal-actor', 'actor', true, '2026-09-16 00:00:00', \TowerDNS\Domain\Account\AccountKind::PERSONAL, 'actor'));
        $accounts->method('getEffectiveRole')->with(42, 'actor')->willReturn(TeamRole::OWNER);
        $accounts->expects(self::never())->method('transferOwnership');
        $users   = $this->createMock(UserRepositoryInterface::class);
        $service = new AccountOwnershipService($accounts, $users, $this->permissionService($accounts));
        $this->expectException(\DomainException::class);
        $service->transfer(new User('actor', 'actor@example.test'), 42, 'target');
    }

    private function permissionService(AccountRepositoryInterface $accounts): PermissionService
    {
        /** @var \PHPUnit\Framework\MockObject\MockObject&AccountRepositoryInterface $accounts */
        $rbac        = new RbacPermissionChecker();
        $memberships = $this->createMock(ZoneMembershipRepositoryInterface::class);
        return new PermissionService($accounts, $memberships, new AuthorizationService($rbac), $rbac);
    }
}

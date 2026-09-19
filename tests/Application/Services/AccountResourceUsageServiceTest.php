<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Application\Services;

use PHPUnit\Framework\TestCase;
use TowerDNS\Application\Repository\AccountRepositoryInterface;
use TowerDNS\Application\Repository\AccountResourceLimitsRepositoryInterface;
use TowerDNS\Application\Repository\ManagedZoneRepositoryInterface;
use TowerDNS\Application\Repository\ProviderAccountRepositoryInterface;
use TowerDNS\Application\Repository\ZoneMembershipRepositoryInterface;
use TowerDNS\Application\Services\AccountResourceUsageService;
use TowerDNS\Application\Services\AuthorizationService;
use TowerDNS\Application\Services\PermissionService;
use TowerDNS\Application\Services\RbacPermissionChecker;
use TowerDNS\Domain\Account\Account;
use TowerDNS\Domain\Account\AccountResourceLimits;
use TowerDNS\Domain\Account\TeamRole;
use TowerDNS\Domain\Auth\User;

final class AccountResourceUsageServiceTest extends TestCase
{
    public function testUsageSnapshotContainsCountsAndUnlimitedLimits(): void
    {
        $accounts = $this->createMock(AccountRepositoryInterface::class);
        $accounts->method('getEffectiveRole')->with(42, 'user')->willReturn(TeamRole::OWNER);
        $accounts->method('findById')->with(42)->willReturn(new Account(42, 'Team', 'team', 'user', true, '2026-09-17 00:00:00'));
        $accounts->method('findMemberships')->with(42)->willReturn([new \stdClass(), new \stdClass()]);
        $limits = $this->createMock(AccountResourceLimitsRepositoryInterface::class);
        $limits->expects(self::once())->method('findByAccountId')->with(42)->willReturn(new AccountResourceLimits(42, 10, null, 5));
        $zones = $this->createMock(ManagedZoneRepositoryInterface::class);
        $zones->expects(self::once())->method('findByAccountId')->with(42)->willReturn([new \stdClass()]);
        $providers = $this->createMock(ProviderAccountRepositoryInterface::class);
        $providers->expects(self::once())->method('findByAccountId')->with(42)->willReturn([new \stdClass(), new \stdClass()]);
        $rbac = new RbacPermissionChecker();
        $permissions = new PermissionService($accounts, $this->createMock(ZoneMembershipRepositoryInterface::class), new AuthorizationService($rbac), $rbac);

        $usage = (new AccountResourceUsageService($accounts, $limits, $zones, $providers, $permissions))->forUser(new User('user', 'user@example.test'), 42);

        self::assertSame(1, $usage->usedZones);
        self::assertSame(10, $usage->maxZones);
        self::assertSame(2, $usage->usedMembers);
        self::assertNull($usage->maxMembers);
        self::assertSame(2, $usage->usedProviderAccounts);
        self::assertFalse($usage->membersOverLimit());
    }
}

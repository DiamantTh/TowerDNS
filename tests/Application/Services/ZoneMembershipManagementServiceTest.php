<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Application\Services;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TowerDNS\Application\DTO\AuditContext;
use TowerDNS\Application\Exception\ZoneMembershipException;
use TowerDNS\Application\Repository\AccountRepositoryInterface;
use TowerDNS\Application\Repository\AuditLogRepositoryInterface;
use TowerDNS\Application\Repository\ManagedZoneRepositoryInterface;
use TowerDNS\Application\Repository\UserRepositoryInterface;
use TowerDNS\Application\Repository\ZoneMembershipRepositoryInterface;
use TowerDNS\Application\Services\AuditLogService;
use TowerDNS\Application\Services\AuthorizationService;
use TowerDNS\Application\Services\PermissionService;
use TowerDNS\Application\Services\RbacPermissionChecker;
use TowerDNS\Application\Services\ZoneMembershipManagementService;
use TowerDNS\Domain\Account\Account;
use TowerDNS\Domain\Account\ManagedZone;
use TowerDNS\Domain\Account\TeamRole;
use TowerDNS\Domain\Auth\User;

final class ZoneMembershipManagementServiceTest extends TestCase
{
    public function testGrantUsesTheInternalManagedZoneAndValidatesTheTargetUser(): void
    {
        $zones       = $this->zones();
        $memberships = $this->createMock(ZoneMembershipRepositoryInterface::class);
        $memberships->expects(self::once())->method('grant')->with(
            7,
            'target',
            TeamRole::VIEWER,
            self::isType('string'),
            'actor',
        );
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->expects(self::once())->method('findById')->with('target')->willReturn(new User('target', 'target@example.test'));

        $this->service($zones, $memberships, $users)->grant(
            new User('actor', 'actor@example.test'),
            42,
            7,
            'target',
            TeamRole::VIEWER,
            new AuditContext('actor'),
        );
    }

    public function testRejectsAZoneThatDoesNotBelongToTheRequestedAccount(): void
    {
        $zones = $this->createMock(ManagedZoneRepositoryInterface::class);
        $zones->expects(self::once())->method('findByIdForAccount')->with(7, 42)->willReturn(null);

        $this->expectException(ZoneMembershipException::class);
        $this->expectExceptionMessage(ZoneMembershipException::MANAGED_ZONE_NOT_FOUND);
        $this->service($zones, $this->createMock(ZoneMembershipRepositoryInterface::class), $this->createMock(UserRepositoryInterface::class))
            ->list(new User('actor', 'actor@example.test'), 42, 7);
    }

    public function testRevokeRejectsAnUnknownMembershipInsteadOfDeletingByAnExternalZoneIdentifier(): void
    {
        $memberships = $this->createMock(ZoneMembershipRepositoryInterface::class);
        $memberships->expects(self::once())->method('findMembership')->with(7, 'target')->willReturn(null);
        $memberships->expects(self::never())->method('revoke');

        $this->expectException(ZoneMembershipException::class);
        $this->expectExceptionMessage(ZoneMembershipException::MEMBERSHIP_NOT_FOUND);
        $this->service($this->zones(), $memberships, $this->createMock(UserRepositoryInterface::class))
            ->revoke(new User('actor', 'actor@example.test'), 42, 7, 'target', new AuditContext('actor'));
    }

    /** @return MockObject&ManagedZoneRepositoryInterface */
    private function zones(): ManagedZoneRepositoryInterface
    {
        $zones = $this->createMock(ManagedZoneRepositoryInterface::class);
        $zones->method('findByIdForAccount')->with(7, 42)->willReturn(new ManagedZone(7, 42, 9, 'external-zone-id', 'example.test', '2026-09-16 12:00:00'));
        return $zones;
    }

    private function service(
        ManagedZoneRepositoryInterface $zones,
        ZoneMembershipRepositoryInterface $memberships,
        UserRepositoryInterface $users,
    ): ZoneMembershipManagementService {
        $accounts = $this->createMock(AccountRepositoryInterface::class);
        $accounts->method('getEffectiveRole')->with(42, 'actor')->willReturn(TeamRole::ADMIN);
        $accounts->method('findById')->with(42)->willReturn(new Account(42, 'Team', 'team', 'actor', true, '2026-09-16 00:00:00'));
        $rbac        = new RbacPermissionChecker();
        $permissions = new PermissionService($accounts, $memberships, new AuthorizationService($rbac), $rbac, $zones);

        return new ZoneMembershipManagementService(
            $zones,
            $memberships,
            $users,
            $permissions,
            new AuditLogService($this->createMock(AuditLogRepositoryInterface::class)),
        );
    }
}

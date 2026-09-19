<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Application\Services;

use PHPUnit\Framework\TestCase;
use TowerDNS\Application\Repository\AccountInvitationRepositoryInterface;
use TowerDNS\Application\Repository\AccountRepositoryInterface;
use TowerDNS\Application\Repository\AccountResourceLimitsRepositoryInterface;
use TowerDNS\Application\Repository\ManagedZoneRepositoryInterface;
use TowerDNS\Application\Repository\ProviderAccountRepositoryInterface;
use TowerDNS\Application\Repository\UserRepositoryInterface;
use TowerDNS\Application\Services\AccountInvitationService;
use TowerDNS\Application\Services\AuthorizationService;
use TowerDNS\Application\Services\MailService;
use TowerDNS\Application\Services\PermissionService;
use TowerDNS\Application\Services\RbacPermissionChecker;
use TowerDNS\Application\Services\ResourceLimitService;
use TowerDNS\Domain\Account\Account;
use TowerDNS\Domain\Account\AccountInvitation;
use TowerDNS\Domain\Account\TeamRole;
use TowerDNS\Domain\Auth\User;

final class AccountInvitationServiceTest extends TestCase
{
    public function testInvitationStoresOnlyHashAndCanBeAcceptedOnce(): void
    {
        $accounts = $this->createMock(AccountRepositoryInterface::class);
        $accounts->method('getEffectiveRole')->with(42, 'owner')->willReturn(TeamRole::OWNER);
        $accounts->method('findById')->with(42)->willReturn(new Account(42, 'Team', 'team', 'owner', true, '2026-09-17 00:00:00'));
        $accounts->method('findMembership')->willReturn(null);
        $accounts->expects(self::once())->method('addMembership');
        $accounts->expects(self::never())->method('removeMembership');
        $users  = $this->createMock(UserRepositoryInterface::class);
        $target = new User('target', 'target@example.test');
        $users->method('findByEmail')->with('target@example.test')->willReturn($target);
        $invitations = $this->createMock(AccountInvitationRepositoryInterface::class);
        $invitations->method('findPendingByAccountAndEmail')->willReturn(null);
        $capturedHash = null;
        $invitations->expects(self::once())->method('create')->willReturnCallback(function (int $accountId, string $email, ?string $userId, TeamRole $role, string $invitedBy, string $tokenHash) use (&$capturedHash): int {
            $capturedHash = $tokenHash;
            return 7;
        });
        $created = new AccountInvitation(7, 42, 'target@example.test', 'target', TeamRole::VIEWER, 'owner', str_repeat('a', 64), '2026-09-17 00:00:00', '2999-01-01 00:00:00');
        $invitations->method('findById')->with(7)->willReturn($created);
        $invitations->method('findByTokenHash')->with(hash('sha256', str_repeat('b', 64)))->willReturn($created);
        $invitations->expects(self::once())->method('consume')->with(7, 'target', self::isType('string'), self::isType('string'))->willReturn(true);
        $limitsRepository = $this->createMock(AccountResourceLimitsRepositoryInterface::class);
        $limitsRepository->method('findByAccountId')->with(42)->willReturn(new \TowerDNS\Domain\Account\AccountResourceLimits(42, null, 10, null));
        $accounts->method('findMemberships')->with(42)->willReturn([]);
        $limits      = new ResourceLimitService($limitsRepository, $this->createMock(ManagedZoneRepositoryInterface::class), $accounts, $this->createMock(ProviderAccountRepositoryInterface::class));
        $rbac        = new RbacPermissionChecker();
        $permissions = new PermissionService($accounts, $this->createMock(\TowerDNS\Application\Repository\ZoneMembershipRepositoryInterface::class), new AuthorizationService($rbac), $rbac);

        new AccountInvitationService($invitations, $accounts, $users, $permissions, $limits, new MailService('null://null', 'noreply@example.test', 'TowerDNS'))->create(new User('owner', 'owner@example.test'), 42, 'target@example.test', TeamRole::VIEWER);

        self::assertIsString($capturedHash);
        self::assertSame(64, strlen($capturedHash));
        self::assertTrue(ctype_xdigit($capturedHash));
        new AccountInvitationService($invitations, $accounts, $users, $permissions, $limits, new MailService('null://null', 'noreply@example.test', 'TowerDNS'))->accept($target, str_repeat('b', 64));
    }
}

<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Application\Services;

use PHPUnit\Framework\TestCase;
use TowerDNS\Application\Repository\AccountRepositoryInterface;
use TowerDNS\Application\Repository\ManagedZoneRepositoryInterface;
use TowerDNS\Application\Repository\ProviderAccountRepositoryInterface;
use TowerDNS\Application\Repository\ZoneMembershipRepositoryInterface;
use TowerDNS\Application\Services\AccountManagementService;
use TowerDNS\Application\Services\AuthorizationService;
use TowerDNS\Application\Services\PermissionService;
use TowerDNS\Application\Services\RbacPermissionChecker;
use TowerDNS\Domain\Account\Account;
use TowerDNS\Domain\Account\TeamRole;
use TowerDNS\Domain\Auth\User;

final class AccountManagementServiceTest extends TestCase
{
    public function testCreateRejectsMissingNameOrSlug(): void
    {
        $accounts = $this->createMock(AccountRepositoryInterface::class);
        $accounts->expects(self::never())->method('create');

        $service = $this->service($accounts);

        $this->expectException(\DomainException::class);
        $service->create(new User('owner', 'owner@example.test'), '', 'slug');
    }

    public function testCreateRejectsInvalidSlug(): void
    {
        $accounts = $this->createMock(AccountRepositoryInterface::class);
        $accounts->expects(self::never())->method('create');

        $service = $this->service($accounts);

        $this->expectException(\DomainException::class);
        $service->create(new User('owner', 'owner@example.test'), 'Team', 'Invalid Slug!');
    }

    public function testCreateReservesPersonalSlugNamespace(): void
    {
        $accounts = $this->createMock(AccountRepositoryInterface::class);
        $accounts->expects(self::never())->method('create');
        $service = $this->service($accounts);
        $this->expectException(\DomainException::class);
        $service->create(new User('owner', 'owner@example.test'), 'Collision', 'personal-other');
    }

    public function testCreatePersistsAndReturnsTheNewAccount(): void
    {
        $accounts = $this->createMock(AccountRepositoryInterface::class);
        $accounts->expects(self::once())->method('create')
            ->with('Team', 'team', 'owner', self::isType('string'))
            ->willReturn(42);
        $accounts->method('findById')->with(42)->willReturn(
            new Account(42, 'Team', 'team', 'owner', true, '2026-09-16 00:00:00'),
        );

        $service = $this->service($accounts);
        $account = $service->create(new User('owner', 'owner@example.test'), 'Team', 'team');

        self::assertSame(42, $account->id);
        self::assertSame('team', $account->slug);
    }

    public function testRenameRejectsWithoutManagePermission(): void
    {
        $accounts = $this->createMock(AccountRepositoryInterface::class);
        $accounts->method('getEffectiveRole')->with(42, 'actor')->willReturn(null);
        $accounts->expects(self::never())->method('updateName');

        $service = $this->service($accounts);

        $this->expectException(\TowerDNS\Application\Exception\AuthorizationException::class);
        $service->rename(new User('actor', 'actor@example.test'), 42, 'New Name');
    }

    public function testRenameRejectsEmptyName(): void
    {
        $accounts = $this->createMock(AccountRepositoryInterface::class);
        $accounts->method('getEffectiveRole')->with(42, 'actor')->willReturn(TeamRole::OWNER);
        $accounts->method('findById')->with(42)->willReturn(
            new Account(42, 'Team', 'team', 'actor', true, '2026-09-16 00:00:00'),
        );
        $accounts->expects(self::never())->method('updateName');

        $service = $this->service($accounts);

        $this->expectException(\DomainException::class);
        $service->rename(new User('actor', 'actor@example.test'), 42, '   ');
    }

    public function testRenameUpdatesTheAccountName(): void
    {
        $accounts = $this->createMock(AccountRepositoryInterface::class);
        $accounts->method('getEffectiveRole')->with(42, 'actor')->willReturn(TeamRole::OWNER);
        $accounts->method('findById')->with(42)->willReturn(
            new Account(42, 'Team', 'team', 'actor', true, '2026-09-16 00:00:00'),
        );
        $accounts->expects(self::once())->method('updateName')->with(42, 'New Name');

        $service = $this->service($accounts);
        $service->rename(new User('actor', 'actor@example.test'), 42, 'New Name');
    }

    public function testDeactivateRequiresDeletePermission(): void
    {
        $accounts = $this->createMock(AccountRepositoryInterface::class);
        $accounts->method('getEffectiveRole')->with(42, 'actor')->willReturn(TeamRole::ADMIN);
        $accounts->expects(self::never())->method('deactivate');

        $service = $this->service($accounts);

        $this->expectException(\TowerDNS\Application\Exception\AuthorizationException::class);
        $service->deactivate(new User('actor', 'actor@example.test'), 42);
    }

    public function testDeactivateDeactivatesTheAccount(): void
    {
        $accounts = $this->createMock(AccountRepositoryInterface::class);
        $accounts->method('getEffectiveRole')->with(42, 'actor')->willReturn(TeamRole::OWNER);
        $accounts->method('findById')->with(42)->willReturn(
            new Account(42, 'Team', 'team', 'actor', true, '2026-09-16 00:00:00'),
        );
        $accounts->expects(self::once())->method('deactivate')->with(42);

        $service = $this->service($accounts);
        $service->deactivate(new User('actor', 'actor@example.test'), 42);
    }

    public function testPersonalAccountCannotBeDeactivated(): void
    {
        $accounts = $this->createMock(AccountRepositoryInterface::class);
        $accounts->method('getEffectiveRole')->with(42, 'actor')->willReturn(TeamRole::OWNER);
        $accounts->method('findById')->with(42)->willReturn(new Account(42, 'Personal', 'personal-actor', 'actor', true, '2026-09-16 00:00:00', \TowerDNS\Domain\Account\AccountKind::PERSONAL, 'actor'));
        $accounts->expects(self::never())->method('deactivate');
        $this->expectException(\DomainException::class);
        $this->service($accounts)->deactivate(new User('actor', 'actor@example.test'), 42);
    }

    public function testOrganizationCanBeReactivated(): void
    {
        $accounts = $this->createMock(AccountRepositoryInterface::class);
        $accounts->method('getEffectiveRole')->with(42, 'actor')->willReturn(TeamRole::OWNER);
        $accounts->method('findById')->with(42)->willReturn(new Account(42, 'Team', 'team', 'actor', false, '2026-09-16 00:00:00'));
        $accounts->expects(self::once())->method('activate')->with(42);

        $this->service($accounts)->activate(new User('actor', 'actor@example.test'), 42);
    }

    public function testPersonalAccountCannotBeReactivatedThroughStatusAction(): void
    {
        $accounts = $this->createMock(AccountRepositoryInterface::class);
        $accounts->method('getEffectiveRole')->with(42, 'actor')->willReturn(TeamRole::OWNER);
        $accounts->method('findById')->with(42)->willReturn(new Account(42, 'Personal', 'personal-actor', 'actor', true, '2026-09-16 00:00:00', \TowerDNS\Domain\Account\AccountKind::PERSONAL, 'actor'));
        $accounts->expects(self::never())->method('activate');

        $this->expectException(\DomainException::class);
        $this->service($accounts)->activate(new User('actor', 'actor@example.test'), 42);
    }

    public function testOrganizationDetailsUpdatePersistsOptionalFields(): void
    {
        $accounts = $this->createMock(AccountRepositoryInterface::class);
        $accounts->method('getEffectiveRole')->with(42, 'actor')->willReturn(TeamRole::OWNER);
        $accounts->method('findById')->with(42)->willReturn(new Account(42, 'Team', 'team', 'actor', true, '2026-09-16 00:00:00'));
        $accounts->expects(self::once())->method('updateOrganizationDetails')->with(42, 'New Team', 'C-42', 'crm-42');

        $this->service($accounts)->updateOrganizationDetails(new User('actor', 'actor@example.test'), 42, ' New Team ', ' C-42 ', 'crm-42');
    }

    public function testOrganizationDeletionIsBlockedWhileResourcesExist(): void
    {
        $accounts = $this->createMock(AccountRepositoryInterface::class);
        $accounts->method('getEffectiveRole')->with(42, 'actor')->willReturn(TeamRole::OWNER);
        $accounts->method('findById')->with(42)->willReturn(new Account(42, 'Team', 'team', 'actor', true, '2026-09-16 00:00:00'));
        $accounts->method('countMemberships')->with(42)->willReturn(1);
        $zones = $this->createMock(ManagedZoneRepositoryInterface::class);
        $zones->method('countByAccountId')->with(42)->willReturn(1);
        $providers = $this->createMock(ProviderAccountRepositoryInterface::class);
        $providers->expects(self::never())->method('countByAccountId');
        $accounts->expects(self::never())->method('delete');

        $service = new AccountManagementService($accounts, $this->permissionService($accounts), $zones, $providers);
        $this->expectException(\DomainException::class);
        $service->delete(new User('actor', 'actor@example.test'), 42);
    }

    private function service(AccountRepositoryInterface $accounts): AccountManagementService
    {
        return new AccountManagementService(
            $accounts,
            $this->permissionService($accounts),
            $this->createMock(ManagedZoneRepositoryInterface::class),
            $this->createMock(ProviderAccountRepositoryInterface::class),
        );
    }

    private function permissionService(AccountRepositoryInterface $accounts): PermissionService
    {
        $rbac        = new RbacPermissionChecker();
        $memberships = $this->createMock(ZoneMembershipRepositoryInterface::class);
        return new PermissionService($accounts, $memberships, new AuthorizationService($rbac), $rbac);
    }
}

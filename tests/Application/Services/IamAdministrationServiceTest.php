<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Application\Services;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TowerDNS\Application\Contracts\TransactionRunnerInterface;
use TowerDNS\Application\DTO\AuditContext;
use TowerDNS\Application\DTO\StepUpAction;
use TowerDNS\Application\Exception\AuthorizationException;
use TowerDNS\Application\Exception\StepUpRequiredException;
use TowerDNS\Application\Repository\AccountRepositoryInterface;
use TowerDNS\Application\Repository\AccountResourceLimitsRepositoryInterface;
use TowerDNS\Application\Repository\AuditLogRepositoryInterface;
use TowerDNS\Application\Repository\ManagedZoneRepositoryInterface;
use TowerDNS\Application\Repository\ProviderAccountRepositoryInterface;
use TowerDNS\Application\Repository\RoleRepositoryInterface;
use TowerDNS\Application\Repository\StepUpProofNonceRepositoryInterface;
use TowerDNS\Application\Repository\UserRepositoryInterface;
use TowerDNS\Application\Services\AuditLogService;
use TowerDNS\Application\Services\AuthorizationService;
use TowerDNS\Application\Services\IamAdministrationService;
use TowerDNS\Application\Services\StepUpProofService;
use TowerDNS\Application\Services\UserLifecycleService;
use TowerDNS\Domain\Account\AuditLogEntry;
use TowerDNS\Domain\Auth\Permission;
use TowerDNS\Domain\Auth\Role;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Infrastructure\Clock\SystemClock;

final class IamAdministrationServiceTest extends TestCase
{
    public function testUserManageAloneCannotAssignElevatedRoleToAnotherUser(): void
    {
        $actor = new User('actor', 'actor@example.test', [
            new Role('user-manager', 'User manager', [Permission::USER_MANAGE]),
        ]);
        $target            = new User('target', 'target@example.test');
        $elevated          = new Role('settings-admin', 'Settings administrator', [Permission::SYSTEM_SETTINGS_MANAGE]);
        [$service, $users] = $this->service($actor, $target, $elevated);

        $users->expects(self::never())->method('syncRoles');

        try {
            $service->syncRoles($actor, $target->id, [$elevated->id], new AuditContext('actor', 'actor'));
            self::fail('A user manager without ROLE_MANAGE must not delegate a privileged role.');
        } catch (AuthorizationException) {
            // Expected: USER_MANAGE does not imply ROLE_MANAGE or delegation.
        }
    }

    public function testUserManageAloneCannotPromoteItself(): void
    {
        $actor = new User('actor', 'actor@example.test', [
            new Role('user-manager', 'User manager', [Permission::USER_MANAGE]),
        ]);
        $candidate         = new User('candidate', 'candidate@example.test');
        $elevated          = new Role('settings-admin', 'Settings administrator', [Permission::SYSTEM_SETTINGS_MANAGE]);
        [$service, $users] = $this->service($actor, $candidate, $elevated);
        $users->expects(self::never())->method('syncRoles');

        $this->expectException(AuthorizationException::class);
        $service->syncRoles($actor, $actor->id, [$elevated->id], new AuditContext('actor', 'actor'));
    }

    public function testRoleManagerCannotCreateOrAssignPermissionsTheyDoNotHold(): void
    {
        $actor = new User('actor', 'actor@example.test', [
            new Role('role-manager', 'Role manager', [Permission::USER_MANAGE, Permission::ROLE_MANAGE]),
        ]);
        $target                    = new User('target', 'target@example.test');
        $elevated                  = new Role('settings-admin', 'Settings administrator', [Permission::SYSTEM_SETTINGS_MANAGE]);
        [$service, $users, $roles] = $this->service($actor, $target, $elevated);

        $users->expects(self::never())->method('syncRoles');
        $roles->expects(self::never())->method('save');

        try {
            $service->syncRoles($actor, $target->id, [$elevated->id], new AuditContext('actor', 'actor'));
            self::fail('Role assignment must stay within the actor permission ceiling.');
        } catch (AuthorizationException) {
            // Expected: role-management does not grant permissions the actor lacks.
        }

        try {
            $service->saveRole($actor, $elevated, new AuditContext('actor', 'actor'));
            self::fail('Role creation must stay within the actor permission ceiling.');
        } catch (AuthorizationException) {
            // Expected: role-management does not grant permissions the actor lacks.
        }
    }

    public function testBuiltInSuperadminCanAssignRoleAndAuditSeparatesActorFromEffectiveUser(): void
    {
        $effectiveActor = new User('effective-admin', 'effective-admin@example.test', [
            new Role('limited-iam', 'Scoped IAM administrator', [Permission::USER_MANAGE, Permission::ROLE_MANAGE, Permission::RECORD_UPDATE]),
        ]);
        $target                       = new User('target', 'target@example.test');
        $assigned                     = new Role('dns-editor', 'DNS editor', [Permission::RECORD_UPDATE]);
        [$service, $users, , $proofs] = $this->service($effectiveActor, $target, $assigned, new AuditContext('root', 'effective-admin', impersonationSessionId: 'switch-1'));

        $users->expects(self::once())->method('syncRoles')->with('target', ['dns-editor']);

        $proof = $proofs->issue('effective-admin', StepUpAction::IAM_USER_ROLES, 'target', 'switch-1', 'totp');
        $service->syncRoles($effectiveActor, 'target', ['dns-editor'], new AuditContext('root', 'effective-admin', impersonationSessionId: 'switch-1'), $proof);
    }

    public function testOriginalSuperadminDoesNotAuthorizeImpersonatedUsersRoleAssignment(): void
    {
        $effectiveActor = new User('effective-viewer', 'viewer@example.test', [
            new Role('limited-iam', 'Limited IAM administrator', [Permission::USER_MANAGE, Permission::ROLE_MANAGE]),
        ]);
        $target            = new User('target', 'target@example.test');
        $elevated          = new Role('settings-admin', 'Settings administrator', [Permission::SYSTEM_SETTINGS_MANAGE]);
        [$service, $users] = $this->service(
            $effectiveActor,
            $target,
            $elevated,
            new AuditContext('root', 'effective-viewer', impersonationSessionId: 'switch-2'),
        );
        $users->expects(self::never())->method('syncRoles');

        $this->expectException(AuthorizationException::class);
        $service->syncRoles(
            $effectiveActor,
            $target->id,
            [$elevated->id],
            new AuditContext('root', 'effective-viewer', impersonationSessionId: 'switch-2'),
        );
    }

    public function testOnlyBuiltInSuperadminMayAssignTheSuperadminRole(): void
    {
        $actor = new User('actor', 'actor@example.test', [
            new Role('role-manager', 'Role manager', [Permission::USER_MANAGE, Permission::ROLE_MANAGE]),
        ]);
        $target            = new User('target', 'target@example.test');
        $superadmin        = new Role('superadmin', 'Superadmin', [], isBuiltIn: true);
        [$service, $users] = $this->service($actor, $target, $superadmin);
        $users->expects(self::never())->method('syncRoles');

        $this->expectException(AuthorizationException::class);
        $service->syncRoles($actor, 'target', ['superadmin'], new AuditContext('actor', 'actor'));
    }

    public function testAuthorizedRoleChangeStillRequiresFreshActionBoundStepUp(): void
    {
        $actor = new User('actor', 'actor@example.test', [
            new Role('role-manager', 'Role manager', [Permission::USER_MANAGE, Permission::ROLE_MANAGE, Permission::RECORD_UPDATE]),
        ]);
        $target            = new User('target', 'target@example.test');
        $assignable        = new Role('dns-editor', 'DNS editor', [Permission::RECORD_UPDATE]);
        [$service, $users] = $this->service($actor, $target, $assignable);
        $users->expects(self::never())->method('syncRoles');

        $this->expectException(StepUpRequiredException::class);
        $service->syncRoles($actor, $target->id, [$assignable->id], new AuditContext('actor', 'actor'));
    }

    public function testNonSuperadminCannotChangeBuiltInSuperadminEvenWithEveryPermission(): void
    {
        $actor = new User('actor', 'actor@example.test', [
            new Role('custom-all', 'Custom all-permissions role', Permission::cases()),
        ]);
        $target = new User('root', 'root@example.test', [
            new Role('superadmin', 'Superadmin', [], isBuiltIn: true),
        ]);
        $superadmin        = new Role('superadmin', 'Superadmin', [], isBuiltIn: true);
        [$service, $users] = $this->service($actor, $target, $superadmin);
        $users->expects(self::never())->method('syncRoles');
        $users->expects(self::never())->method('setActive');

        try {
            $service->syncRoles($actor, $target->id, [$superadmin->id], new AuditContext('actor', 'actor'));
            self::fail('A non-built-in role must not administer the built-in superadmin account.');
        } catch (AuthorizationException) {
            // Expected: this protection is role-identity based, not permission-count based.
        }

        $this->expectException(AuthorizationException::class);
        $service->setUserActive($actor, $target->id, false, new AuditContext('actor', 'actor'));
    }

    /**
     * @return array{IamAdministrationService, UserRepositoryInterface&MockObject, RoleRepositoryInterface&MockObject, StepUpProofService}
     */
    private function service(User $actor, User $target, Role $candidate, ?AuditContext $context = null): array
    {
        /** @var UserRepositoryInterface&MockObject $users */
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findById')->willReturnCallback(static fn(string $id): ?User => $id === $actor->id ? $actor : null);
        $users->method('findByIdForAdministration')->willReturnCallback(static fn(string $id): ?User => $id === $target->id ? $target : null);
        $users->method('countActiveUsersWithRole')->willReturn(2);

        /** @var RoleRepositoryInterface&MockObject $roles */
        $roles = $this->createMock(RoleRepositoryInterface::class);
        $roles->method('findByIds')->willReturnCallback(static fn(array $ids): array => in_array($candidate->id, $ids, true) ? [$candidate] : []);
        $roles->method('findById')->willReturnCallback(static fn(string $id): ?Role => $id === $candidate->id ? $candidate : null);

        $transactions = $this->createMock(TransactionRunnerInterface::class);
        $transactions->method('run')->willReturnCallback(static fn(callable $operation): mixed => $operation());

        $auditContext    = $context ?? new AuditContext('actor', 'actor');
        $auditRepository = $this->createMock(AuditLogRepositoryInterface::class);
        $auditRepository->expects(self::atLeastOnce())->method('append')->with(
            self::callback(static fn(AuditLogEntry $entry): bool => $entry->actorUserId === $auditContext->actorUserId
                && $entry->effectiveUserId                                              === $auditContext->effectiveUserId
                && $entry->impersonationSessionId                                       === $auditContext->impersonationSessionId),
            self::isType('string'),
        );
        $audit = new AuditLogService($auditRepository);

        $lifecycle = new UserLifecycleService(
            $transactions,
            $users,
            $this->createMock(AccountRepositoryInterface::class),
            $this->createMock(AccountResourceLimitsRepositoryInterface::class),
            $this->createMock(ManagedZoneRepositoryInterface::class),
            $this->createMock(ProviderAccountRepositoryInterface::class),
        );

        $nonces = $this->createMock(StepUpProofNonceRepositoryInterface::class);
        $nonces->method('claim')->willReturn(true);
        $stepUpProofs = new StepUpProofService(str_repeat('k', 32), new SystemClock(), $nonces);
        $service      = new IamAdministrationService(
            $users,
            $roles,
            new AuthorizationService(),
            new \TowerDNS\Domain\Auth\PermissionRegistry(),
            $transactions,
            $audit,
            $lifecycle,
            $stepUpProofs,
        );

        return [$service, $users, $roles, $stepUpProofs];
    }
}

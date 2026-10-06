<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Application\Services;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
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
use TowerDNS\Application\Repository\WebAuthnCredentialRepositoryInterface;
use TowerDNS\Application\Services\AccountRecoveryIssuerInterface;
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
use Webauthn\CredentialRecord;
use Webauthn\TrustPath\EmptyTrustPath;

/** @psalm-api Runtime discovery by PHPUnit or local module loading is not statically visible. */
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
        $target                                        = new User('target', 'target@example.test');
        $assigned                                      = new Role('dns-editor', 'DNS editor', [Permission::RECORD_UPDATE]);
        [$service, $users, , $proofs, , $auditEntries] = $this->service($effectiveActor, $target, $assigned);

        $users->expects(self::once())->method('syncRoles')->with('target', ['dns-editor']);

        $proof = $proofs->issue('effective-admin', StepUpAction::IAM_USER_ROLES, 'target', 'switch-1', 'totp', hash('sha256', 'totp'));
        $service->syncRoles($effectiveActor, 'target', ['dns-editor'], new AuditContext('root', 'effective-admin', impersonationSessionId: 'switch-1'), $proof);
        $entry = $auditEntries[0] ?? null;
        self::assertInstanceOf(AuditLogEntry::class, $entry);
        self::assertSame('root', $entry->actorUserId);
        self::assertSame('effective-admin', $entry->effectiveUserId);
        self::assertSame('switch-1', $entry->impersonationSessionId);
    }

    public function testOriginalSuperadminDoesNotAuthorizeImpersonatedUsersRoleAssignment(): void
    {
        $effectiveActor = new User('effective-viewer', 'viewer@example.test', [
            new Role('limited-iam', 'Limited IAM administrator', [Permission::USER_MANAGE, Permission::ROLE_MANAGE]),
        ]);
        $target            = new User('target', 'target@example.test');
        $elevated          = new Role('settings-admin', 'Settings administrator', [Permission::SYSTEM_SETTINGS_MANAGE]);
        [$service, $users] = $this->service($effectiveActor, $target, $elevated);
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

    public function testAuthorizedAdminCanRevokeOnlyTheTargetsFinalKeyWithFidoStepUpAndAudit(): void
    {
        $actor                                                     = new User('actor', 'actor@example.test', [new Role('user-manager', 'User manager', [Permission::USER_MANAGE])]);
        $target                                                    = new User('target', 'target@example.test');
        [$service, $users, , $proofs, $credentials, $auditEntries] = $this->service($actor, $target, new Role('unused', 'Unused', []));
        $users->method('fetchPasswordHash')->with($target->email)->willReturn(password_hash('temporary-password', PASSWORD_BCRYPT));
        $adminCredential  = $this->credentialRow('admin-key', $actor->id);
        $targetCredential = $this->credentialRow('target-key', $target->id);
        $credentials->method('findByUserId')->willReturnCallback(static fn(string $userId): array => match ($userId) {
            'actor'  => [$adminCredential],
            'target' => [$targetCredential],
            default  => [],
        });
        $credentials->expects(self::once())->method('delete')->with('target-key', 'target');
        $targetBinding = StepUpAction::iamUserWebAuthnCredentialTarget($target->id, 'target-key');
        $proof         = $proofs->issue($actor->id, StepUpAction::IAM_USER_WEBAUTHN_REVOKE, $targetBinding, null, 'webauthn', hash('sha256', 'admin-key'));

        $service->revokeLastWebAuthnCredential($actor, $target->id, 'target-key', new AuditContext($actor->id, $actor->id), $proof);

        $revocations = array_values(array_filter($auditEntries->getArrayCopy(), static fn(AuditLogEntry $entry): bool => $entry->action === 'iam.user.webauthn.credential.revoked'));
        self::assertCount(1, $revocations);
        $revocation = $revocations[0] ?? null;
        self::assertInstanceOf(AuditLogEntry::class, $revocation);
        self::assertSame($actor->id, $revocation->actorUserId);
        self::assertSame($target->id, $revocation->targetId);
        self::assertSame($actor->id, $revocation->effectiveUserId);
        self::assertSame('webauthn', $revocation->metadataJson['step_up_method'] ?? null);
        self::assertSame(hash('sha256', 'target-key'), $revocation->metadataJson['credential_id_hash'] ?? null);
        self::assertSame('password', $revocation->metadataJson['remaining_login_path'] ?? null);
        self::assertStringNotContainsString('target-key', json_encode($revocation, JSON_THROW_ON_ERROR));
    }

    public function testExpiredAdminFidoStepUpCannotRevokeFinalKeyEvenWhenTargetHasPasswordRecoveryPath(): void
    {
        $actor                               = new User('actor', 'actor@example.test', [new Role('user-manager', 'User manager', [Permission::USER_MANAGE])]);
        $target                              = new User('target', 'target@example.test');
        [$service, $users, , , $credentials] = $this->service($actor, $target, new Role('unused', 'Unused', []));
        $users->method('fetchPasswordHash')->willReturn(password_hash('recovery-password', PASSWORD_BCRYPT));
        $adminCredential  = $this->credentialRow('admin-key', $actor->id);
        $targetCredential = $this->credentialRow('target-key', $target->id);
        $credentials->method('findByUserId')->willReturnCallback(static fn(string $userId): array => $userId === 'actor' ? [$adminCredential] : [$targetCredential]);
        $credentials->expects(self::never())->method('delete');

        $now      = new \DateTimeImmutable();
        $oldClock = new readonly class ($now->modify('-301 seconds')) implements \Psr\Clock\ClockInterface {
            public function __construct(private \DateTimeImmutable $instant) {}

            #[\Override]
            public function now(): \DateTimeImmutable
            {
                return $this->instant;
            }
        };
        $nonces = $this->createMock(StepUpProofNonceRepositoryInterface::class);
        $nonces->method('claim')->willReturn(true);
        $expiredProof = new StepUpProofService(str_repeat('k', 32), $oldClock, $nonces)->issue(
            $actor->id,
            StepUpAction::IAM_USER_WEBAUTHN_REVOKE,
            StepUpAction::iamUserWebAuthnCredentialTarget($target->id, 'target-key'),
            null,
            'webauthn',
            hash('sha256', 'admin-key'),
        );

        $this->expectException(StepUpRequiredException::class);
        $service->revokeLastWebAuthnCredential(
            $actor,
            $target->id,
            'target-key',
            new AuditContext($actor->id, $actor->id),
            $expiredProof,
        );
    }

    public function testAdminCredentialRevocationRequiresUserManagePermissionAndTargetCeiling(): void
    {
        $target                                                                                  = new User('target', 'target@example.test');
        $noPermission                                                                            = new User('viewer', 'viewer@example.test');
        [$deniedService, $deniedUsers, , $deniedProofs, $deniedCredentials, $deniedAuditEntries] = $this->service($noPermission, $target, new Role('unused', 'Unused', []));
        $deniedUsers->method('fetchPasswordHash')->willReturn(password_hash('temporary-password', PASSWORD_BCRYPT));
        $deniedCredentials->method('findByUserId')->willReturn([$this->credentialRow('target-key', $target->id)]);
        $deniedCredentials->expects(self::never())->method('delete');
        $proof = $deniedProofs->issue($noPermission->id, StepUpAction::IAM_USER_WEBAUTHN_REVOKE, StepUpAction::iamUserWebAuthnCredentialTarget($target->id, 'target-key'), null, 'webauthn', hash('sha256', 'admin-key'));
        try {
            $deniedService->revokeLastWebAuthnCredential($noPermission, $target->id, 'target-key', new AuditContext($noPermission->id, $noPermission->id), $proof);
            self::fail('A user without USER_MANAGE must not revoke credentials.');
        } catch (AuthorizationException) {
            // Expected: authentication recovery remains behind existing IAM permission checks.
        }
        $deniedEntry = $deniedAuditEntries[0] ?? null;
        self::assertInstanceOf(AuditLogEntry::class, $deniedEntry);
        self::assertSame('iam.user.webauthn.credential.revoke.denied', $deniedEntry->action);
        self::assertSame($noPermission->id, $deniedEntry->actorUserId);

        $limited                                                                = new User('limited', 'limited@example.test', [new Role('user-manager', 'User manager', [Permission::USER_MANAGE])]);
        $elevatedTarget                                                         = new User('elevated', 'elevated@example.test', [new Role('settings-admin', 'Settings administrator', [Permission::SYSTEM_SETTINGS_MANAGE])]);
        [$ceilingService, $ceilingUsers, , $ceilingProofs, $ceilingCredentials] = $this->service($limited, $elevatedTarget, new Role('unused', 'Unused', []));
        $ceilingUsers->method('fetchPasswordHash')->willReturn(password_hash('temporary-password', PASSWORD_BCRYPT));
        $ceilingCredentials->method('findByUserId')->willReturn([$this->credentialRow('target-key', $elevatedTarget->id)]);
        $ceilingCredentials->expects(self::never())->method('delete');
        $ceilingProof = $ceilingProofs->issue($limited->id, StepUpAction::IAM_USER_WEBAUTHN_REVOKE, StepUpAction::iamUserWebAuthnCredentialTarget($elevatedTarget->id, 'target-key'), null, 'webauthn', hash('sha256', 'admin-key'));
        $this->expectException(AuthorizationException::class);
        $ceilingService->revokeLastWebAuthnCredential($limited, $elevatedTarget->id, 'target-key', new AuditContext($limited->id, $limited->id), $ceilingProof);
    }

    public function testTotpPasswordAndMismatchedTargetProofsCannotRevokeFinalKey(): void
    {
        $actor  = new User('actor', 'actor@example.test', [new Role('user-manager', 'User manager', [Permission::USER_MANAGE])]);
        $target = new User('target', 'target@example.test');
        foreach (['totp', 'password'] as $method) {
            [$service, $users, , $proofs, $credentials] = $this->service($actor, $target, new Role('unused', 'Unused', []));
            $users->method('fetchPasswordHash')->willReturn(password_hash('temporary-password', PASSWORD_BCRYPT));
            $credentials->method('findByUserId')->willReturnCallback(fn(string $userId): array => [$this->credentialRow($userId === $actor->id ? 'admin-key' : 'target-key', $userId)]);
            $credentials->expects(self::never())->method('delete');
            $proof = $proofs->issue($actor->id, StepUpAction::IAM_USER_WEBAUTHN_REVOKE, StepUpAction::iamUserWebAuthnCredentialTarget($target->id, 'target-key'), null, $method, $method === 'totp' ? hash('sha256', 'totp') : null);
            try {
                $service->revokeLastWebAuthnCredential($actor, $target->id, 'target-key', new AuditContext($actor->id, $actor->id), $proof);
                self::fail('TOTP and password proofs must not authorize FIDO2 recovery.');
            } catch (AuthorizationException) {
                // Expected: the administrative operation accepts only WebAuthn proofs.
            }
        }

        [$unownedService, $unownedUsers, , $unownedProofs, $unownedCredentials] = $this->service($actor, $target, new Role('unused', 'Unused', []));
        $unownedUsers->method('fetchPasswordHash')->willReturn(password_hash('temporary-password', PASSWORD_BCRYPT));
        $unownedCredentials->method('findByUserId')->willReturnCallback(fn(string $userId): array => [$this->credentialRow($userId === $actor->id ? 'admin-key' : 'target-key', $userId)]);
        $unownedCredentials->expects(self::never())->method('delete');
        $unownedProof = $unownedProofs->issue(
            $actor->id,
            StepUpAction::IAM_USER_WEBAUTHN_REVOKE,
            StepUpAction::iamUserWebAuthnCredentialTarget($target->id, 'target-key'),
            null,
            'webauthn',
            hash('sha256', 'credential-not-owned-by-admin'),
        );
        try {
            $unownedService->revokeLastWebAuthnCredential($actor, $target->id, 'target-key', new AuditContext($actor->id, $actor->id), $unownedProof);
            self::fail('The WebAuthn step-up credential must belong to the active administrator.');
        } catch (AuthorizationException) {
            // Expected: the assertion credential must be registered to the administrator.
        }

        [$service, $users, , $proofs, $credentials] = $this->service($actor, $target, new Role('unused', 'Unused', []));
        $users->method('fetchPasswordHash')->willReturn(password_hash('temporary-password', PASSWORD_BCRYPT));
        $credentials->method('findByUserId')->willReturnCallback(fn(string $userId): array => [$this->credentialRow($userId === $actor->id ? 'admin-key' : 'target-key', $userId)]);
        $credentials->expects(self::never())->method('delete');
        $wrongTargetProof = $proofs->issue($actor->id, StepUpAction::IAM_USER_WEBAUTHN_REVOKE, StepUpAction::iamUserWebAuthnCredentialTarget($target->id, 'different-key'), null, 'webauthn', hash('sha256', 'admin-key'));
        $this->expectException(StepUpRequiredException::class);
        $service->revokeLastWebAuthnCredential($actor, $target->id, 'target-key', new AuditContext($actor->id, $actor->id), $wrongTargetProof);
    }

    public function testImpersonationMissingPasswordAndNonFinalKeysCannotBeRevoked(): void
    {
        $actor                                                                                      = new User('actor', 'actor@example.test', [new Role('user-manager', 'User manager', [Permission::USER_MANAGE])]);
        $target                                                                                     = new User('target', 'target@example.test');
        [$impersonatedService, $impersonatedUsers, , $impersonatedProofs, $impersonatedCredentials] = $this->service($actor, $target, new Role('unused', 'Unused', []));
        $impersonatedUsers->method('fetchPasswordHash')->willReturn(password_hash('temporary-password', PASSWORD_BCRYPT));
        $impersonatedCredentials->method('findByUserId')->willReturnCallback(fn(string $userId): array => [$this->credentialRow($userId === $actor->id ? 'admin-key' : 'target-key', $userId)]);
        $impersonatedCredentials->expects(self::never())->method('delete');
        $switchContext = new AuditContext('original-admin', $actor->id, impersonationSessionId: 'switch-1');
        $switchProof   = $impersonatedProofs->issue($actor->id, StepUpAction::IAM_USER_WEBAUTHN_REVOKE, StepUpAction::iamUserWebAuthnCredentialTarget($target->id, 'target-key'), 'switch-1', 'webauthn', hash('sha256', 'admin-key'));
        try {
            $impersonatedService->revokeLastWebAuthnCredential($actor, $target->id, 'target-key', $switchContext, $switchProof);
            self::fail('Impersonation must not be accepted for administrative recovery.');
        } catch (AuthorizationException) {
            // Expected: the original administrator identity cannot be used through a switched session.
        }

        [$passwordlessService, $passwordlessUsers, , $passwordlessProofs, $passwordlessCredentials] = $this->service($actor, $target, new Role('unused', 'Unused', []));
        $passwordlessUsers->method('fetchPasswordHash')->willReturn(null);
        $passwordlessCredentials->method('findByUserId')->willReturn([$this->credentialRow('target-key', $target->id)]);
        $passwordlessCredentials->expects(self::never())->method('delete');
        $validProof = $passwordlessProofs->issue($actor->id, StepUpAction::IAM_USER_WEBAUTHN_REVOKE, StepUpAction::iamUserWebAuthnCredentialTarget($target->id, 'target-key'), null, 'webauthn', hash('sha256', 'admin-key'));
        try {
            $passwordlessService->revokeLastWebAuthnCredential($actor, $target->id, 'target-key', new AuditContext($actor->id, $actor->id), $validProof);
            self::fail('A passwordless target needs an authorized admin password reset before key revocation.');
        } catch (\DomainException) {
            // Expected: password login must be restored before removing the only key.
        }

        [$multipleService, $multipleUsers, , $multipleProofs, $multipleCredentials] = $this->service($actor, $target, new Role('unused', 'Unused', []));
        $multipleUsers->method('fetchPasswordHash')->willReturn(password_hash('temporary-password', PASSWORD_BCRYPT));
        $multipleCredentials->method('findByUserId')->willReturn([$this->credentialRow('target-key', $target->id), $this->credentialRow('other-key', $target->id)]);
        $multipleCredentials->expects(self::never())->method('delete');
        $multipleProof = $multipleProofs->issue($actor->id, StepUpAction::IAM_USER_WEBAUTHN_REVOKE, StepUpAction::iamUserWebAuthnCredentialTarget($target->id, 'target-key'), null, 'webauthn', hash('sha256', 'admin-key'));
        $this->expectException(\DomainException::class);
        $multipleService->revokeLastWebAuthnCredential($actor, $target->id, 'target-key', new AuditContext($actor->id, $actor->id), $multipleProof);
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

    public function testAccountRecoveryAuthorizationRequiresUserManageFido2TargetBindingAndNoImpersonation(): void
    {
        $actor  = new User('actor', 'actor@example.test', [new Role('user-manager', 'User manager', [Permission::USER_MANAGE])]);
        $target = new User('target', 'target@example.test'); // Deliberately passwordless/passkey-only.
        $issuer = $this->createMock(AccountRecoveryIssuerInterface::class);
        $issuer->expects(self::once())->method('authorize')->with($target, $actor->id, self::isInstanceOf(AuditContext::class))->willReturn([
            'recovery_id' => 'recovery-id', 'raw_ticket' => str_repeat('a', 64), 'expires_at' => '2026-10-08 00:00:00',
        ]);
        [$service, , , $proofs, $credentials] = $this->service($actor, $target, new Role('unused', 'Unused', []), $issuer);
        $credentials->method('findByUserId')->willReturn([$this->credentialRow('admin-key', $actor->id)]);
        $proof = $proofs->issue($actor->id, StepUpAction::IAM_USER_ACCOUNT_RECOVERY, $target->id, null, 'webauthn', hash('sha256', 'admin-key'));
        self::assertSame('recovery-id', $service->authorizeAccountRecovery($actor, $target->id, new AuditContext($actor->id, $actor->id), $proof)['recovery_id']);
    }

    public function testAccountRecoveryRejectsOtherFactorsWrongTargetPermissionCeilingAndImpersonation(): void
    {
        $actor  = new User('actor', 'actor@example.test', [new Role('user-manager', 'User manager', [Permission::USER_MANAGE])]);
        $target = new User('target', 'target@example.test');
        $issuer = $this->createMock(AccountRecoveryIssuerInterface::class);
        $issuer->expects(self::never())->method('authorize');
        [$service, , , $proofs, $credentials] = $this->service($actor, $target, new Role('unused', 'Unused', []), $issuer);
        $credentials->method('findByUserId')->willReturn([$this->credentialRow('admin-key', $actor->id)]);

        foreach (['password', 'totp'] as $method) {
            $proof = $proofs->issue($actor->id, StepUpAction::IAM_USER_ACCOUNT_RECOVERY, $target->id, null, $method, $method === 'password' ? null : hash('sha256', 'admin-key'));
            try {
                $service->authorizeAccountRecovery($actor, $target->id, new AuditContext($actor->id, $actor->id), $proof);
                self::fail('Password and TOTP must not authorize administrative account recovery.');
            } catch (AuthorizationException) {
                // Expected: only a WebAuthn proof is accepted.
            }
        }

        $wrongTargetProof = $proofs->issue($actor->id, StepUpAction::IAM_USER_ACCOUNT_RECOVERY, 'another-target', null, 'webauthn', hash('sha256', 'admin-key'));
        try {
            $service->authorizeAccountRecovery($actor, $target->id, new AuditContext($actor->id, $actor->id), $wrongTargetProof);
            self::fail('Recovery step-up must be target-bound.');
        } catch (StepUpRequiredException) {
            // Expected.
        }

        $ownedSwitchProof = $proofs->issue($actor->id, StepUpAction::IAM_USER_ACCOUNT_RECOVERY, $target->id, 'switch-1', 'webauthn', hash('sha256', 'admin-key'));
        try {
            $service->authorizeAccountRecovery($actor, $target->id, new AuditContext('root', $actor->id, impersonationSessionId: 'switch-1'), $ownedSwitchProof);
            self::fail('Administrative recovery cannot run while impersonating another user.');
        } catch (AuthorizationException) {
            // Expected.
        }

        $delegated                            = new User('delegated', 'delegated@example.test', [new Role('restricted', 'Restricted', [Permission::SYSTEM_SETTINGS_MANAGE])]);
        [$ceilingService, , , $ceilingProofs] = $this->service($actor, $delegated, new Role('unused', 'Unused', []), $issuer);
        $ceilingProof                         = $ceilingProofs->issue($actor->id, StepUpAction::IAM_USER_ACCOUNT_RECOVERY, $delegated->id, null, 'webauthn', hash('sha256', 'admin-key'));
        $this->expectException(AuthorizationException::class);
        $ceilingService->authorizeAccountRecovery($actor, $delegated->id, new AuditContext($actor->id, $actor->id), $ceilingProof);
    }

    public function testAccountRecoveryRequiresUserManage(): void
    {
        $actor  = new User('viewer', 'viewer@example.test');
        $target = new User('target', 'target@example.test');
        $issuer = $this->createMock(AccountRecoveryIssuerInterface::class);
        $issuer->expects(self::never())->method('authorize');
        [$service, , , $proofs, $credentials] = $this->service($actor, $target, new Role('unused', 'Unused', []), $issuer);
        $credentials->method('findByUserId')->willReturn([$this->credentialRow('viewer-key', $actor->id)]);
        $proof = $proofs->issue($actor->id, StepUpAction::IAM_USER_ACCOUNT_RECOVERY, $target->id, null, 'webauthn', hash('sha256', 'viewer-key'));

        $this->expectException(AuthorizationException::class);
        $service->authorizeAccountRecovery($actor, $target->id, new AuditContext($actor->id, $actor->id), $proof);
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
     * @return array{IamAdministrationService, UserRepositoryInterface&MockObject, RoleRepositoryInterface&MockObject, StepUpProofService, WebAuthnCredentialRepositoryInterface&MockObject, \ArrayObject<int, AuditLogEntry>}
     */
    private function service(User $actor, User $target, Role $candidate, ?AccountRecoveryIssuerInterface $recoveryIssuer = null): array
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

        $auditRepository = $this->createMock(AuditLogRepositoryInterface::class);
        $auditEntries    = new \ArrayObject();
        $auditRepository->method('append')->willReturnCallback(static function (AuditLogEntry $entry, string $_createdAt) use ($auditEntries): void {
            $auditEntries->append($entry);
        });
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
        $stepUpProofs        = new StepUpProofService(str_repeat('k', 32), new SystemClock(), $nonces);
        $webAuthnCredentials = $this->createMock(WebAuthnCredentialRepositoryInterface::class);
        $service             = new IamAdministrationService(
            $users,
            $roles,
            new AuthorizationService(),
            new \TowerDNS\Domain\Auth\PermissionRegistry(),
            $transactions,
            $audit,
            $lifecycle,
            $stepUpProofs,
            $webAuthnCredentials,
            $recoveryIssuer,
        );

        return [$service, $users, $roles, $stepUpProofs, $webAuthnCredentials, $auditEntries];
    }

    /** @return array{credential_id: string, name: string, created_at: string, last_used_at: null, attachment: string, aaguid: string, transports: list<string>, backup_eligible: false, backup_state: false, source: CredentialRecord} */
    private function credentialRow(string $credentialId, string $userId): array
    {
        $source = new CredentialRecord($credentialId, 'public-key', [], 'none', EmptyTrustPath::create(), Uuid::fromString('00000000-0000-0000-0000-000000000000'), 'public-key', $userId, 0);
        return [
            'credential_id'   => $credentialId,
            'name'            => 'Security key',
            'created_at'      => '2026-01-01 00:00:00',
            'last_used_at'    => null,
            'attachment'      => 'cross-platform',
            'aaguid'          => '00000000-0000-0000-0000-000000000000',
            'transports'      => [],
            'backup_eligible' => false,
            'backup_state'    => false,
            'source'          => $source,
        ];
    }
}

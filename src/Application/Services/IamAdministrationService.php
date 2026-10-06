<?php

declare(strict_types=1);

namespace TowerDNS\Application\Services;

use TowerDNS\Application\Contracts\TransactionRunnerInterface;
use TowerDNS\Application\DTO\AuditContext;
use TowerDNS\Application\DTO\StepUpAction;
use TowerDNS\Application\DTO\StepUpProof;
use TowerDNS\Application\Exception\AuthorizationException;
use TowerDNS\Application\Exception\StepUpRequiredException;
use TowerDNS\Application\Repository\RoleRepositoryInterface;
use TowerDNS\Application\Repository\UserRepositoryInterface;
use TowerDNS\Application\Repository\WebAuthnCredentialRepositoryInterface;
use TowerDNS\Domain\Auth\Permission;
use TowerDNS\Domain\Auth\PermissionRegistry;
use TowerDNS\Domain\Auth\Role;
use TowerDNS\Domain\Auth\User;

final readonly class IamAdministrationService
{
    public function __construct(
        private UserRepositoryInterface $users,
        private RoleRepositoryInterface $roles,
        private AuthorizationService $authorization,
        private PermissionRegistry $permissions,
        private TransactionRunnerInterface $transactions,
        private AuditLogService $audit,
        private UserLifecycleService $lifecycle,
        private StepUpProofService $stepUpProofs,
        private WebAuthnCredentialRepositoryInterface $webAuthnCredentials,
        private ?AccountRecoveryIssuerInterface $accountRecovery = null,
    ) {}

    /** @param list<string> $roleIds */
    public function syncRoles(User $effectiveActor, string $userId, array $roleIds, AuditContext $context, ?StepUpProof $stepUp = null): void
    {
        $roleIds = array_values(array_unique($roleIds));
        $this->guardAndAuditDenial($context, 'iam.user.roles.denied', 'user', $userId, ['requested_role_ids' => $roleIds], function () use ($effectiveActor, $userId, $roleIds, $context, $stepUp): void {
            $this->transactions->run(function () use ($effectiveActor, $userId, $roleIds, $context, $stepUp): void {
                $this->users->lockSuperadminRoleForMutation();
                $actor = $this->requireActiveActor($effectiveActor->id);
                $this->authorization->assert($actor, Permission::USER_MANAGE);
                $this->authorization->assert($actor, Permission::ROLE_MANAGE);
                $target = $this->users->findByIdForAdministration($userId);
                if (!$target instanceof User) {
                    throw new \DomainException('Target user does not exist.');
                }

                $this->assertTargetWithinActorAuthority($actor, $target);
                $before   = $this->roleIds($target);
                $assigned = $this->loadRequestedRoles($roleIds);
                $after    = array_map(static fn(Role $role): string => $role->id, $assigned);
                if (in_array('superadmin', $after, true) && !$this->authorization->isBuiltInSuperadmin($actor)) {
                    throw new AuthorizationException('Only the built-in superadmin may assign the superadmin role.');
                }
                $this->assertPermissionsDelegable($actor, $assigned);
                $this->assertLastSuperadminRetained($target, $after);
                $this->assertStepUp($actor, StepUpAction::IAM_USER_ROLES, $userId, $context, $stepUp);

                $this->users->syncRoles($userId, $after);
                $this->audit->recordWithContext($context, 'iam.user.roles.changed', 'user', $userId, ['role_ids' => $before], ['role_ids' => $after], ['step_up_method' => $stepUp?->method]);
            });
        });
    }

    public function saveRole(User $effectiveActor, Role $role, AuditContext $context, ?StepUpProof $stepUp = null): void
    {
        $this->guardAndAuditDenial($context, 'iam.role.change.denied', 'role', $role->id, ['requested_permission_ids' => $role->getPermissionIds()], function () use ($effectiveActor, $role, $context, $stepUp): void {
            $this->transactions->run(function () use ($effectiveActor, $role, $context, $stepUp): void {
                $this->users->lockSuperadminRoleForMutation();
                $actor = $this->requireActiveActor($effectiveActor->id);
                $this->authorization->assert($actor, Permission::ROLE_MANAGE);
                $beforeRole = $this->roles->findById($role->id);
                if ($beforeRole?->isBuiltIn || $role->isBuiltIn) {
                    throw new AuthorizationException('Built-in roles are immutable.');
                }
                $this->assertPermissionsDelegable($actor, [$role]);
                $this->assertStepUp(
                    $actor,
                    $beforeRole instanceof Role ? StepUpAction::IAM_ROLE_SAVE : StepUpAction::IAM_ROLE_CREATE,
                    $beforeRole instanceof Role ? $role->id : 'new',
                    $context,
                    $stepUp,
                );
                $this->roles->save($role);
                $this->audit->recordWithContext(
                    $context,
                    $beforeRole instanceof Role ? 'iam.role.updated' : 'iam.role.created',
                    'role',
                    $role->id,
                    $beforeRole instanceof Role ? ['name' => $beforeRole->name, 'permission_ids' => $beforeRole->getPermissionIds()] : null,
                    ['name' => $role->name, 'permission_ids' => $role->getPermissionIds()],
                    ['step_up_method' => $stepUp?->method],
                );
            });
        });
    }

    public function deleteRole(User $effectiveActor, string $roleId, AuditContext $context, ?StepUpProof $stepUp = null): void
    {
        $this->guardAndAuditDenial($context, 'iam.role.delete.denied', 'role', $roleId, null, function () use ($effectiveActor, $roleId, $context, $stepUp): void {
            $this->transactions->run(function () use ($effectiveActor, $roleId, $context, $stepUp): void {
                $this->users->lockSuperadminRoleForMutation();
                $actor = $this->requireActiveActor($effectiveActor->id);
                $this->authorization->assert($actor, Permission::ROLE_MANAGE);
                $role = $this->roles->findById($roleId);
                if (!$role instanceof Role) {
                    return;
                }
                if ($role->isBuiltIn) {
                    throw new AuthorizationException('Built-in roles are immutable.');
                }
                $this->assertPermissionsDelegable($actor, [$role]);
                $this->assertStepUp($actor, StepUpAction::IAM_ROLE_DELETE, $roleId, $context, $stepUp);
                $this->roles->delete($roleId);
                $this->audit->recordWithContext($context, 'iam.role.deleted', 'role', $roleId, ['name' => $role->name, 'permission_ids' => $role->getPermissionIds()], null, ['step_up_method' => $stepUp?->method]);
            });
        });
    }

    public function setUserActive(User $effectiveActor, string $userId, bool $active, AuditContext $context, ?StepUpProof $stepUp = null): void
    {
        $this->guardAndAuditDenial($context, 'iam.user.status.denied', 'user', $userId, ['active' => $active], function () use ($effectiveActor, $userId, $active, $context, $stepUp): void {
            $this->transactions->run(function () use ($effectiveActor, $userId, $active, $context, $stepUp): void {
                $this->users->lockSuperadminRoleForMutation();
                $actor = $this->requireActiveActor($effectiveActor->id);
                $this->authorization->assert($actor, Permission::USER_MANAGE);
                $target = $this->users->findByIdForAdministration($userId);
                if (!$target instanceof User) {
                    throw new \DomainException('Target user does not exist.');
                }
                if (!$active && $target->id === $actor->id) {
                    throw new AuthorizationException('An actor cannot deactivate their own account.');
                }
                $this->assertTargetWithinActorAuthority($actor, $target);
                if (!$active && $target->active && $this->hasBuiltInSuperadmin($target) && $this->users->countActiveUsersWithRole('superadmin') <= 1) {
                    throw new \DomainException('The last active superadmin cannot be deactivated.');
                }
                $this->assertStepUp($actor, StepUpAction::IAM_USER_STATUS, $userId, $context, $stepUp);
                $this->users->setActive($userId, $active);
                $this->audit->recordWithContext($context, 'iam.user.status.changed', 'user', $userId, ['active' => $target->active], ['active' => $active], ['step_up_method' => $stepUp?->method]);
            });
        });
    }

    public function deleteUser(User $effectiveActor, string $userId, AuditContext $context, ?StepUpProof $stepUp = null): User
    {
        return $this->guardAndAuditDenial($context, 'iam.user.delete.denied', 'user', $userId, null, fn(): User => $this->transactions->run(function () use ($effectiveActor, $userId, $context, $stepUp): User {
            $this->users->lockSuperadminRoleForMutation();
            $actor = $this->requireActiveActor($effectiveActor->id);
            $this->authorization->assert($actor, Permission::USER_MANAGE);
            $target = $this->users->findByIdForAdministration($userId);
            if (!$target instanceof User) {
                throw new \DomainException('Target user does not exist.');
            }
            if ($target->id === $actor->id) {
                throw new AuthorizationException('An actor cannot delete their own account.');
            }
            $this->assertTargetWithinActorAuthority($actor, $target);
            if ($target->active && $this->hasBuiltInSuperadmin($target) && $this->users->countActiveUsersWithRole('superadmin') <= 1) {
                throw new \DomainException('The last active superadmin cannot be deleted.');
            }
            $this->assertStepUp($actor, StepUpAction::IAM_USER_DELETE, $userId, $context, $stepUp);
            $this->lifecycle->delete($userId);
            $this->audit->recordWithContext($context, 'iam.user.deleted', 'user', $userId, ['role_ids' => $this->roleIds($target), 'active' => $target->active], null, ['step_up_method' => $stepUp?->method]);
            return $target;
        }));
    }

    public function revokeLastWebAuthnCredential(
        User $effectiveActor,
        string $targetUserId,
        string $credentialId,
        AuditContext $context,
        ?StepUpProof $stepUp = null,
    ): void {
        $this->guardAndAuditDenial(
            $context,
            'iam.user.webauthn.credential.revoke.denied',
            'user',
            $targetUserId,
            ['credential_id_hash' => hash('sha256', $credentialId)],
            function () use ($effectiveActor, $targetUserId, $credentialId, $context, $stepUp): void {
                $this->transactions->run(function () use ($effectiveActor, $targetUserId, $credentialId, $context, $stepUp): void {
                    $this->users->lockSuperadminRoleForMutation();
                    $this->users->lockUserForAuthenticationMutation($targetUserId);

                    $actor = $this->requireActiveActor($effectiveActor->id);
                    if ($context->effectiveUserId !== $actor->id || $context->impersonationSessionId !== null) {
                        throw new AuthorizationException('Administrative credential recovery is unavailable during impersonation.');
                    }
                    $this->authorization->assert($actor, Permission::USER_MANAGE);

                    $target = $this->users->findByIdForAdministration($targetUserId);
                    if (!$target instanceof User || !$target->active) {
                        throw new \DomainException('Target user is unavailable for authentication recovery.');
                    }
                    $this->assertTargetWithinActorAuthority($actor, $target);

                    $targetCredentials = $this->webAuthnCredentials->findByUserId($targetUserId);
                    if (count($targetCredentials) !== 1 || $targetCredentials[0]['source']->publicKeyCredentialId !== $credentialId) {
                        throw new \DomainException('Administrative recovery only revokes the target user’s final WebAuthn credential.');
                    }

                    $passwordHash = $this->users->fetchPasswordHash($target->email);
                    if ($passwordHash === null || password_get_info($passwordHash)['algo'] === null) {
                        throw new \DomainException('A usable administrator-set password login is required before the final WebAuthn credential can be revoked.');
                    }

                    $targetId = StepUpAction::iamUserWebAuthnCredentialTarget($targetUserId, $credentialId);
                    if (!$this->stepUpProofs->isValid($stepUp, $actor->id, StepUpAction::IAM_USER_WEBAUTHN_REVOKE, $targetId, null)) {
                        throw new StepUpRequiredException(StepUpAction::IAM_USER_WEBAUTHN_REVOKE, $targetId);
                    }
                    if (!$stepUp instanceof StepUpProof || $stepUp->method !== 'webauthn' || !is_string($stepUp->credentialIdHash)) {
                        throw new AuthorizationException('Final WebAuthn credential recovery requires administrator FIDO2 confirmation.');
                    }
                    $stepUpCredentialIdHash = $stepUp->credentialIdHash;
                    $adminCredentialIsOwned = array_any(
                        $this->webAuthnCredentials->findByUserId($actor->id),
                        static fn(array $entry): bool => hash_equals($stepUpCredentialIdHash, hash('sha256', $entry['source']->publicKeyCredentialId)),
                    );
                    if (!$adminCredentialIsOwned) {
                        throw new AuthorizationException('The FIDO2 confirmation credential does not belong to the active administrator.');
                    }
                    if (!$this->stepUpProofs->consumeOnce($stepUp, $actor->id, StepUpAction::IAM_USER_WEBAUTHN_REVOKE, $targetId, null)) {
                        throw new AuthorizationException('The step-up proof has already been used.');
                    }

                    $credentialIdHash = hash('sha256', $credentialId);
                    $this->webAuthnCredentials->delete($credentialId, $targetUserId);
                    $this->audit->recordWithContext(
                        $context,
                        'iam.user.webauthn.credential.revoked',
                        'user',
                        $targetUserId,
                        ['webauthn_credentials' => 1],
                        ['webauthn_credentials' => 0],
                        [
                            'credential_id_hash'      => $credentialIdHash,
                            'remaining_login_path'    => 'password',
                            'step_up_method'          => 'webauthn',
                            'step_up_credential_hash' => $stepUpCredentialIdHash,
                        ],
                    );
                });
            },
        );
    }

    /** @return array{recovery_id:string,raw_ticket:string,expires_at:string} */
    public function authorizeAccountRecovery(
        User $effectiveActor,
        string $targetUserId,
        AuditContext $context,
        ?StepUpProof $stepUp = null,
    ): array {
        return $this->guardAndAuditDenial(
            $context,
            'iam.user.account_recovery.authorization.denied',
            'user',
            $targetUserId,
            null,
            fn(): array => $this->transactions->run(function () use ($effectiveActor, $targetUserId, $context, $stepUp): array {
                $this->users->lockSuperadminRoleForMutation();
                $this->users->lockUserForAuthenticationMutation($targetUserId);
                $actor = $this->requireActiveActor($effectiveActor->id);
                if ($context->effectiveUserId !== $actor->id || $context->impersonationSessionId !== null) {
                    throw new AuthorizationException('Account recovery authorization is unavailable during impersonation.');
                }
                $this->authorization->assert($actor, Permission::USER_MANAGE);
                $target = $this->users->findByIdForAdministration($targetUserId);
                if (!$target instanceof User || !$target->active) {
                    throw new \DomainException('Target user is unavailable for account recovery.');
                }
                $this->assertTargetWithinActorAuthority($actor, $target);

                $targetBoundId = $targetUserId;
                if (!$this->stepUpProofs->isValid($stepUp, $actor->id, StepUpAction::IAM_USER_ACCOUNT_RECOVERY, $targetBoundId, null)) {
                    throw new StepUpRequiredException(StepUpAction::IAM_USER_ACCOUNT_RECOVERY, $targetBoundId);
                }
                if (!$stepUp instanceof StepUpProof || $stepUp->method !== 'webauthn' || !is_string($stepUp->credentialIdHash)) {
                    throw new AuthorizationException('Account recovery authorization requires administrator FIDO2 confirmation.');
                }
                $stepUpCredentialIdHash = $stepUp->credentialIdHash;
                $ownsCredential         = array_any(
                    $this->webAuthnCredentials->findByUserId($actor->id),
                    static fn(array $entry): bool => hash_equals($stepUpCredentialIdHash, hash('sha256', $entry['source']->publicKeyCredentialId)),
                );
                if (!$ownsCredential) {
                    throw new AuthorizationException('The FIDO2 confirmation credential does not belong to the active administrator.');
                }
                if (!$this->stepUpProofs->consumeOnce($stepUp, $actor->id, StepUpAction::IAM_USER_ACCOUNT_RECOVERY, $targetBoundId, null)) {
                    throw new AuthorizationException('The step-up proof has already been used.');
                }
                if (!$this->accountRecovery instanceof AccountRecoveryIssuerInterface) {
                    throw new \LogicException('Account recovery service is not configured.');
                }

                return $this->accountRecovery->authorize($target, $actor->id, $context);
            }),
        );
    }

    private function requireActiveActor(string $actorId): User
    {
        return $this->users->findById($actorId) ?? throw new AuthorizationException('The effective actor is no longer active.');
    }

    /**
     * @param list<string> $roleIds
     * @return list<Role>
     */
    private function loadRequestedRoles(array $roleIds): array
    {
        foreach ($roleIds as $roleId) {
            if ($roleId === '' || strlen($roleId) > 128) {
                throw new AuthorizationException('Invalid role assignment.');
            }
        }
        $roles = $this->roles->findByIds($roleIds);
        $found = array_map(static fn(Role $role): string => $role->id, $roles);
        sort($found);
        $expected = $roleIds;
        sort($expected);
        if ($found !== $expected) {
            throw new AuthorizationException('Unknown role assignment.');
        }
        return $roles;
    }

    /** @param list<Role> $roles */
    private function assertPermissionsDelegable(User $actor, array $roles): void
    {
        if ($this->authorization->isBuiltInSuperadmin($actor)) {
            return;
        }
        $granted   = array_fill_keys($this->authorization->grantedPermissionIds($actor), true);
        $requested = [];
        foreach ($roles as $role) {
            $requested = [...$requested, ...($role->isBuiltInSuperadmin() ? $this->permissions->ids() : $role->getPermissionIds())];
        }
        foreach (array_unique($requested) as $permissionId) {
            if (!$this->permissions->has($permissionId) || !isset($granted[$permissionId])) {
                throw new AuthorizationException('The actor cannot delegate permissions they do not hold.');
            }
        }
    }

    private function assertTargetWithinActorAuthority(User $actor, User $target): void
    {
        if ($this->authorization->isBuiltInSuperadmin($actor)) {
            return;
        }
        if ($this->hasBuiltInSuperadmin($target)) {
            throw new AuthorizationException('Only the built-in superadmin may change another superadmin account.');
        }
        $this->assertPermissionsDelegable($actor, $target->roles);
    }

    /** @param list<string> $newRoleIds */
    private function assertLastSuperadminRetained(User $target, array $newRoleIds): void
    {
        if ($target->active && $this->hasBuiltInSuperadmin($target) && !in_array('superadmin', $newRoleIds, true)
                            && $this->users->countActiveUsersWithRole('superadmin') <= 1) {
            throw new \DomainException('The last active superadmin must retain the superadmin role.');
        }
    }

    private function hasBuiltInSuperadmin(User $user): bool
    {
        return $this->authorization->isBuiltInSuperadmin($user);
    }

    private function assertStepUp(User $actor, string $action, string $targetId, AuditContext $context, ?StepUpProof $proof): void
    {
        if (!$this->stepUpProofs->isValid($proof, $actor->id, $action, $targetId, $context->impersonationSessionId)) {
            throw new StepUpRequiredException($action, $targetId);
        }

        if (!$this->stepUpProofs->consumeOnce($proof, $actor->id, $action, $targetId, $context->impersonationSessionId)) {
            throw new AuthorizationException('The step-up proof has already been used.');
        }
    }

    /** @return list<string> */
    private function roleIds(User $user): array
    {
        $ids = array_map(static fn(Role $role): string => $role->id, $user->roles);
        sort($ids);
        return $ids;
    }

    /** @param array<string, mixed>|null $attempt @param callable(): mixed $operation */
    private function guardAndAuditDenial(AuditContext $context, string $action, string $targetType, string $targetId, ?array $attempt, callable $operation): mixed
    {
        try {
            return $operation();
        } catch (AuthorizationException|\DomainException $error) {
            $this->audit->recordWithContext($context, $action, $targetType, $targetId, null, null, [
                'reason'  => $error instanceof StepUpRequiredException ? 'step_up_required' : ($error instanceof AuthorizationException ? 'forbidden' : 'invariant'),
                'attempt' => $attempt,
            ]);
            throw $error;
        }
    }
}

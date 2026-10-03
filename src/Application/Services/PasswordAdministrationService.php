<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Services;

use Doctrine\DBAL\Connection;
use TowerDNS\Application\DTO\AuditContext;
use TowerDNS\Application\DTO\StepUpAction;
use TowerDNS\Application\DTO\StepUpProof;
use TowerDNS\Application\Exception\AuthorizationException;
use TowerDNS\Application\Exception\PasswordResetException;
use TowerDNS\Application\Repository\UserRepositoryInterface;
use TowerDNS\Domain\Auth\Permission;
use TowerDNS\Domain\Auth\PermissionRegistry;
use TowerDNS\Domain\Auth\User;

/** Shared application path for privileged password changes. */
final readonly class PasswordAdministrationService
{
    public function __construct(
        private Connection              $connection,
        private UserRepositoryInterface $users,
        private PasswordPolicy          $policy,
        private AuthorizationService    $authorization,
        private PermissionRegistry      $permissions,
        private StepUpProofService      $stepUpProofs,
        private AuditLogService         $audit,
    ) {}

    /**
     * @return int number of invalidated API keys
     * @throws \InvalidArgumentException when the password does not meet policy
     * @throws PasswordResetException when the active target user is unavailable
     */
    public function setByAdministrator(
        User $effectiveActor,
        string $targetUserId,
        string $password,
        bool $keepApiKeys,
        AuditContext $context,
        ?StepUpProof $stepUp = null,
    ): int {
        $this->policy->assertValid($password);

        try {
            return $this->connection->transactional(function () use ($effectiveActor, $targetUserId, $password, $keepApiKeys, $context, $stepUp): int {
                $this->users->lockSuperadminRoleForMutation();
                $actor = $this->users->findById($effectiveActor->id);
                if (!$actor instanceof User || $context->effectiveUserId !== $actor->id) {
                    throw new AuthorizationException('The effective actor is no longer authorized.');
                }
                if ($context->impersonationSessionId !== null) {
                    throw new AuthorizationException('Administrative password changes are unavailable during impersonation.');
                }
                $this->authorization->assert($actor, Permission::USER_MANAGE);

                $target = $this->users->findByIdForAdministration($targetUserId);
                if (!$target instanceof User || !$target->active) {
                    throw new PasswordResetException('Password reset target user is unavailable.');
                }
                $this->assertTargetWithinActorAuthority($actor, $target);
                $this->assertStepUp($actor, $targetUserId, $stepUp);

                $hash = password_hash($password, PASSWORD_ARGON2ID);
                $this->users->updatePasswordHash($targetUserId, $hash);
                $revokedKeys = $keepApiKeys ? 0 : $this->users->invalidateApiKeys($targetUserId);
                $this->audit->recordWithContext(
                    $context,
                    'iam.user.password.changed',
                    'user',
                    $targetUserId,
                    null,
                    ['password_reset' => true],
                    ['api_keys_revoked' => $revokedKeys, 'step_up_method' => $stepUp?->method],
                );

                return $revokedKeys;
            });
        } catch (AuthorizationException|PasswordResetException $error) {
            $this->audit->recordWithContext($context, 'iam.user.password.denied', 'user', $targetUserId, null, null, [
                'reason' => $error instanceof \TowerDNS\Application\Exception\StepUpRequiredException ? 'step_up_required' : 'forbidden',
            ]);
            throw $error;
        } finally {
            sodium_memzero($password);
        }
    }

    private function assertTargetWithinActorAuthority(User $actor, User $target): void
    {
        if ($this->authorization->isBuiltInSuperadmin($actor)) {
            return;
        }

        $granted = array_fill_keys($this->authorization->grantedPermissionIds($actor), true);
        foreach ($target->roles as $role) {
            if ($role->isBuiltInSuperadmin()) {
                throw new AuthorizationException('Only the built-in superadmin may change another superadmin account.');
            }
            foreach ($role->getPermissionIds() as $permissionId) {
                if (!$this->permissions->has($permissionId) || !isset($granted[$permissionId])) {
                    throw new AuthorizationException('The actor cannot administer a user with permissions they do not hold.');
                }
            }
        }
    }

    private function assertStepUp(User $actor, string $targetUserId, ?StepUpProof $proof): void
    {
        if (!$this->stepUpProofs->isValid($proof, $actor->id, StepUpAction::IAM_USER_PASSWORD, $targetUserId, null)) {
            throw new \TowerDNS\Application\Exception\StepUpRequiredException(StepUpAction::IAM_USER_PASSWORD, $targetUserId);
        }
        if (!$this->stepUpProofs->consumeOnce($proof, $actor->id, StepUpAction::IAM_USER_PASSWORD, $targetUserId, null)) {
            throw new AuthorizationException('The step-up proof has already been used.');
        }
    }
}

<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Services;

use TowerDNS\Application\Repository\AccountRepositoryInterface;
use TowerDNS\Application\Repository\UserRepositoryInterface;
use TowerDNS\Domain\Account\Account;
use TowerDNS\Domain\Account\AccountMembership;
use TowerDNS\Domain\Account\TeamRole;
use TowerDNS\Domain\Auth\User;

final readonly class AccountMembershipManagementService
{
    public function __construct(
        private AccountRepositoryInterface $accounts,
        private UserRepositoryInterface $users,
        private PermissionService $permissions,
        private ?ResourceLimitService $resourceLimits = null,
    ) {}

    public function invite(User $actor, int $accountId, string $targetUserId, TeamRole $role): void
    {
        $this->permissions->assertCanManageMembers($accountId, $actor);

        $account = $this->accounts->findById($accountId);
        if (!$account instanceof Account || !$account->isActive) {
            throw new \DomainException('Account not found or inactive.');
        }

        $targetUserId = trim($targetUserId);
        if ($targetUserId === '' || $this->users->findById($targetUserId) === null) {
            throw new \DomainException('Target user not found.');
        }

        if ($role === TeamRole::OWNER) {
            throw new \DomainException('Account ownership can only be changed through ownership transfer.');
        }

        if ($this->accounts->findMembership($accountId, $targetUserId) instanceof AccountMembership) {
            throw new \DomainException('User is already a member of this account.');
        }

        $this->resourceLimits?->assertCanAddMember($accountId);

        $this->accounts->addMembership(
            $accountId,
            $targetUserId,
            $role,
            new \DateTimeImmutable()->format('Y-m-d H:i:s'),
            $actor->id,
        );
    }

    public function revoke(User $actor, int $accountId, string $targetUserId): void
    {
        $this->permissions->assertCanManageMembers($accountId, $actor);

        $account = $this->accounts->findById($accountId);
        if (!$account instanceof Account || !$account->isActive) {
            throw new \DomainException('Account not found or inactive.');
        }

        $membership = $this->accounts->findMembership($accountId, trim($targetUserId));
        if (!$membership instanceof AccountMembership) {
            throw new \DomainException('Membership not found.');
        }

        if ($membership->role === TeamRole::OWNER) {
            throw new \DomainException('Account owners cannot be removed. Transfer ownership first.');
        }

        $this->accounts->removeMembership($accountId, $membership->userId);
    }
}

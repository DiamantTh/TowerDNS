<?php

declare(strict_types=1);

namespace TowerDNS\Application\Services;

use TowerDNS\Application\Repository\AccountRepositoryInterface;
use TowerDNS\Application\Repository\UserRepositoryInterface;
use TowerDNS\Domain\Account\Account;
use TowerDNS\Domain\Account\AccountKind;
use TowerDNS\Domain\Account\AccountMembership;
use TowerDNS\Domain\Auth\Permission;
use TowerDNS\Domain\Auth\User;

final readonly class AccountOwnershipService
{
    public function __construct(
        private AccountRepositoryInterface $accounts,
        private UserRepositoryInterface $users,
        private PermissionService $permissions,
    ) {}

    public function transfer(User $actor, int $accountId, string $newOwnerUserId): void
    {
        $this->permissions->assertAccount($actor, Permission::ACCOUNT_OWNERSHIP_TRANSFER, $accountId);

        $account = $this->accounts->findById($accountId);
        if (!$account instanceof Account || !$account->isActive) {
            throw new \DomainException('Account not found or inactive.');
        }
        if ($account->kind() === AccountKind::PERSONAL) {
            throw new \DomainException('Personal account ownership cannot be transferred.');
        }

        $newOwnerUserId = trim($newOwnerUserId);
        if ($newOwnerUserId === '' || $newOwnerUserId === $account->ownerUserId) {
            throw new \DomainException('Invalid ownership transfer target.');
        }

        $target = $this->users->findById($newOwnerUserId);
        if (!$target instanceof User) {
            throw new \DomainException('Target user is not available for ownership transfer.');
        }

        $membership = $this->accounts->findMembership($accountId, $target->id);
        if (!$membership instanceof AccountMembership) {
            throw new \DomainException('Target user must already be a member of the account.');
        }

        $this->accounts->transferOwnership($accountId, $target->id);
    }
}

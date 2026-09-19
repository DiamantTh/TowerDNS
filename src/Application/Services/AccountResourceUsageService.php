<?php

declare(strict_types=1);

namespace TowerDNS\Application\Services;

use TowerDNS\Application\DTO\AccountResourceUsage;
use TowerDNS\Application\Repository\AccountRepositoryInterface;
use TowerDNS\Application\Repository\AccountResourceLimitsRepositoryInterface;
use TowerDNS\Application\Repository\ManagedZoneRepositoryInterface;
use TowerDNS\Application\Repository\ProviderAccountRepositoryInterface;
use TowerDNS\Domain\Account\Account;
use TowerDNS\Domain\Auth\Permission;
use TowerDNS\Domain\Auth\User;

final readonly class AccountResourceUsageService
{
    public function __construct(
        private AccountRepositoryInterface $accounts,
        private AccountResourceLimitsRepositoryInterface $limits,
        private ManagedZoneRepositoryInterface $zones,
        private ProviderAccountRepositoryInterface $providers,
        private PermissionService $permissions,
    ) {}

    public function forUser(User $user, int $accountId): AccountResourceUsage
    {
        $this->permissions->assertAccount($user, Permission::ACCOUNT_READ, $accountId);
        $account = $this->accounts->findById($accountId);
        if (!$account instanceof Account) {
            throw new \DomainException('Account not found.');
        }

        $limits = $this->limits->findByAccountId($accountId);

        return new AccountResourceUsage(
            accountId: $accountId,
            usedZones: $this->zones->countByAccountId($accountId),
            maxZones: $limits->maxZones,
            usedMembers: $this->accounts->countMemberships($accountId),
            maxMembers: $limits->maxMembers,
            usedProviderAccounts: $this->providers->countByAccountId($accountId),
            maxProviderAccounts: $limits->maxProviderAccounts,
        );
    }
}

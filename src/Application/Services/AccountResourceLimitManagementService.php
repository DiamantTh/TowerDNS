<?php

declare(strict_types=1);

namespace TowerDNS\Application\Services;

use TowerDNS\Application\Repository\AccountRepositoryInterface;
use TowerDNS\Application\Repository\AccountResourceLimitsRepositoryInterface;
use TowerDNS\Domain\Account\Account;
use TowerDNS\Domain\Account\AccountKind;
use TowerDNS\Domain\Account\AccountResourceLimits;
use TowerDNS\Domain\Auth\Permission;
use TowerDNS\Domain\Auth\User;

final readonly class AccountResourceLimitManagementService
{
    public function __construct(
        private AccountRepositoryInterface $accounts,
        private AccountResourceLimitsRepositoryInterface $limits,
        private PermissionService $permissions,
    ) {}

    public function update(User $actor, int $accountId, ?int $maxZones, ?int $maxMembers, ?int $maxProviderAccounts): void
    {
        $this->permissions->assertAccount($actor, Permission::ACCOUNT_UPDATE, $accountId);
        $account = $this->accounts->findById($accountId);
        if (!$account instanceof Account) {
            throw new \DomainException('Account not found.');
        }
        if ($account->kind() === AccountKind::PERSONAL) {
            throw new \DomainException('Personal account limits are managed by the installation administrator.');
        }

        $this->limits->save(new AccountResourceLimits($accountId, $maxZones, $maxMembers, $maxProviderAccounts));
    }
}

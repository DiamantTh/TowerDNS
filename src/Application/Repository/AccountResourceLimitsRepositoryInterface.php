<?php

declare(strict_types=1);

namespace TowerDNS\Application\Repository;

use TowerDNS\Domain\Account\AccountResourceLimits;

interface AccountResourceLimitsRepositoryInterface
{
    public function findByAccountId(int $accountId): AccountResourceLimits;

    public function save(AccountResourceLimits $limits): void;
}

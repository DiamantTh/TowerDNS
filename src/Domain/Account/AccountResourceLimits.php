<?php

declare(strict_types=1);

namespace TowerDNS\Domain\Account;

/** Technical, self-hosted resource limits. Null means unlimited. */
final readonly class AccountResourceLimits
{
    public function __construct(
        public int $accountId,
        public ?int $maxZones,
        public ?int $maxMembers,
        public ?int $maxProviderAccounts,
    ) {
        foreach ([$maxZones, $maxMembers, $maxProviderAccounts] as $limit) {
            if ($limit !== null && $limit < 0) {
                throw new \InvalidArgumentException('Resource limits must be null or non-negative.');
            }
        }
    }
}

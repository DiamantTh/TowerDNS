<?php

declare(strict_types=1);

namespace TowerDNS\Application\DTO;

/**
 * A single, authorization-filtered snapshot used by account administration
 * pages. Null limits mean unlimited; counts are never negative.
 */
final readonly class AccountResourceUsage
{
    public function __construct(
        public int $accountId,
        public int $usedZones,
        public ?int $maxZones,
        public int $usedMembers,
        public ?int $maxMembers,
        public int $usedProviderAccounts,
        public ?int $maxProviderAccounts,
    ) {}

    public function zonesOverLimit(): bool
    {
        return $this->maxZones !== null && $this->usedZones > $this->maxZones;
    }

    public function membersOverLimit(): bool
    {
        return $this->maxMembers !== null && $this->usedMembers > $this->maxMembers;
    }

    public function providerAccountsOverLimit(): bool
    {
        return $this->maxProviderAccounts !== null && $this->usedProviderAccounts > $this->maxProviderAccounts;
    }
}

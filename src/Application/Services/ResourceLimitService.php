<?php

declare(strict_types=1);

namespace TowerDNS\Application\Services;

use TowerDNS\Application\Exception\ResourceLimitExceededException;
use TowerDNS\Application\Repository\AccountRepositoryInterface;
use TowerDNS\Application\Repository\AccountResourceLimitsRepositoryInterface;
use TowerDNS\Application\Repository\ManagedZoneRepositoryInterface;
use TowerDNS\Application\Repository\ProviderAccountRepositoryInterface;

/** Central quota policy for Web, API, and CLI creation workflows. */
final readonly class ResourceLimitService
{
    public function __construct(
        private AccountResourceLimitsRepositoryInterface $limits,
        private ManagedZoneRepositoryInterface $managedZones,
        private AccountRepositoryInterface $accounts,
        private ProviderAccountRepositoryInterface $providerAccounts,
    ) {}

    public function assertCanCreateZone(int $accountId): void
    {
        $limit = $this->limits->findByAccountId($accountId)->maxZones;
        if ($limit === null) {
            return;
        }
        $this->assertLimit($limit, $this->managedZones->countByAccountId($accountId), ResourceLimitExceededException::ZONES);
    }

    public function assertCanAddMember(int $accountId): void
    {
        $limit = $this->limits->findByAccountId($accountId)->maxMembers;
        if ($limit === null) {
            return;
        }
        $this->assertLimit($limit, $this->accounts->countMemberships($accountId), ResourceLimitExceededException::MEMBERS);
    }

    public function assertCanCreateProviderAccount(int $accountId): void
    {
        $limit = $this->limits->findByAccountId($accountId)->maxProviderAccounts;
        if ($limit === null) {
            return;
        }
        $this->assertLimit($limit, $this->providerAccounts->countByAccountId($accountId), ResourceLimitExceededException::PROVIDER_ACCOUNTS);
    }

    private function assertLimit(?int $limit, int $used, string $code): void
    {
        if ($limit !== null && $used >= $limit) {
            throw new ResourceLimitExceededException($code);
        }
    }
}

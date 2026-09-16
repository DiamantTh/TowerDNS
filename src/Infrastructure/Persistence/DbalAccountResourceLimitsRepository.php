<?php

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use TowerDNS\Application\Repository\AccountResourceLimitsRepositoryInterface;
use TowerDNS\Domain\Account\AccountResourceLimits;

final readonly class DbalAccountResourceLimitsRepository implements AccountResourceLimitsRepositoryInterface
{
    public function __construct(private Connection $connection) {}

    public function findByAccountId(int $accountId): AccountResourceLimits
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM account_resource_limits WHERE account_id = ?', [$accountId]);
        if ($row === false) {
            return new AccountResourceLimits($accountId, null, null, null);
        }
        return new AccountResourceLimits($accountId, $this->nullableInt($row['max_zones']), $this->nullableInt($row['max_members']), $this->nullableInt($row['max_provider_accounts']));
    }

    public function save(AccountResourceLimits $limits): void
    {
        $this->connection->executeStatement(
            'INSERT INTO account_resource_limits (account_id, max_zones, max_members, max_provider_accounts) VALUES (?, ?, ?, ?) ON CONFLICT(account_id) DO UPDATE SET max_zones = excluded.max_zones, max_members = excluded.max_members, max_provider_accounts = excluded.max_provider_accounts',
            [$limits->accountId, $limits->maxZones, $limits->maxMembers, $limits->maxProviderAccounts],
        );
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}

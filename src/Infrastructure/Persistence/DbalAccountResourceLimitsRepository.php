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
        $values = [
            'max_zones'             => $limits->maxZones,
            'max_members'           => $limits->maxMembers,
            'max_provider_accounts' => $limits->maxProviderAccounts,
        ];
        if ($this->connection->fetchOne('SELECT 1 FROM account_resource_limits WHERE account_id = ?', [$limits->accountId]) !== false) {
            $this->connection->update('account_resource_limits', $values, ['account_id' => $limits->accountId]);
            return;
        }
        $this->connection->insert('account_resource_limits', ['account_id' => $limits->accountId, ...$values]);
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}

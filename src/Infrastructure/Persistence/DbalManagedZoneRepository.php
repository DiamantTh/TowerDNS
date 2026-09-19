<?php

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use TowerDNS\Application\Repository\ManagedZoneRepositoryInterface;
use TowerDNS\Domain\Account\ManagedZone;

final readonly class DbalManagedZoneRepository implements ManagedZoneRepositoryInterface
{
    public function __construct(private Connection $connection) {}

    public function findById(int $id): ?ManagedZone
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM managed_zones WHERE id = ?', [$id]);
        return $row === false ? null : $this->hydrate($row);
    }

    public function findByIdForAccount(int $id, int $accountId): ?ManagedZone
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM managed_zones WHERE id = ? AND account_id = ?', [$id, $accountId]);
        return $row === false ? null : $this->hydrate($row);
    }

    public function findByProviderZone(int $providerAccountId, string $providerZoneId): ?ManagedZone
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM managed_zones WHERE provider_account_id = ? AND provider_zone_id = ?',
            [$providerAccountId, $providerZoneId],
        );
        return $row === false ? null : $this->hydrate($row);
    }

    public function findByAccountId(int $accountId): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT * FROM managed_zones WHERE account_id = ? ORDER BY canonical_name', [$accountId]);
        return array_map($this->hydrate(...), $rows);
    }

    public function countByAccountId(int $accountId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM managed_zones WHERE account_id = ?',
            [$accountId],
        );
    }

    public function create(int $accountId, int $providerAccountId, string $providerZoneId, string $canonicalName, string $createdAt): int
    {
        $providerBelongs = $this->connection->fetchOne(
            'SELECT 1 FROM provider_accounts WHERE id = ? AND account_id = ?',
            [$providerAccountId, $accountId],
        );
        if ($providerBelongs === false) {
            throw new \DomainException('Provider account does not belong to the managed-zone account.');
        }

        $this->connection->insert('managed_zones', [
            'account_id'          => $accountId,
            'provider_account_id' => $providerAccountId,
            'provider_zone_id'    => $providerZoneId,
            'canonical_name'      => $canonicalName,
            'created_at'          => $createdAt,
        ]);
        return (int) $this->connection->lastInsertId();
    }

    public function delete(int $id): void
    {
        $this->connection->delete('managed_zones', ['id' => $id]);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): ManagedZone
    {
        return new ManagedZone(
            (int) $row['id'],
            (int) $row['account_id'],
            (int) $row['provider_account_id'],
            (string) $row['provider_zone_id'],
            (string) $row['canonical_name'],
            (string) ($row['created_at'] ?? ''),
        );
    }
}

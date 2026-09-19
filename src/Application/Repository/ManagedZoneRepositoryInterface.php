<?php

declare(strict_types=1);

namespace TowerDNS\Application\Repository;

use TowerDNS\Domain\Account\ManagedZone;

interface ManagedZoneRepositoryInterface
{
    public function findById(int $id): ?ManagedZone;

    public function findByIdForAccount(int $id, int $accountId): ?ManagedZone;

    public function findByProviderZone(int $providerAccountId, string $providerZoneId): ?ManagedZone;

    /** @return list<ManagedZone> */
    public function findByAccountId(int $accountId): array;

    public function countByAccountId(int $accountId): int;

    public function create(int $accountId, int $providerAccountId, string $providerZoneId, string $canonicalName, string $createdAt): int;

    public function delete(int $id): void;
}

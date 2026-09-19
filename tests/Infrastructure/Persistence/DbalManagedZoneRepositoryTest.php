<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use TowerDNS\Infrastructure\Persistence\DbalManagedZoneRepository;

final class DbalManagedZoneRepositoryTest extends TestCase
{
    private Connection $connection;
    private DbalManagedZoneRepository $repository;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('CREATE TABLE accounts (id INTEGER PRIMARY KEY, name TEXT)');
        $this->connection->executeStatement('CREATE TABLE provider_accounts (id INTEGER PRIMARY KEY, account_id INTEGER NOT NULL, UNIQUE(id, account_id))');
        $this->connection->executeStatement('CREATE TABLE managed_zones (id INTEGER PRIMARY KEY AUTOINCREMENT, account_id INTEGER NOT NULL, provider_account_id INTEGER NOT NULL, provider_zone_id TEXT NOT NULL, canonical_name TEXT NOT NULL, created_at TEXT NOT NULL, UNIQUE(provider_account_id, provider_zone_id))');
        $this->connection->executeStatement('INSERT INTO accounts (id, name) VALUES (1, \'A\'), (2, \'B\')');
        $this->connection->executeStatement('INSERT INTO provider_accounts (id, account_id) VALUES (10, 1), (20, 2)');
        $this->repository = new DbalManagedZoneRepository($this->connection);
    }

    public function testSameExternalZoneIdIsAllowedForDifferentProviderAccounts(): void
    {
        $first  = $this->repository->create(1, 10, 'external-1', 'example.org', '2026-09-16 00:00:00');
        $second = $this->repository->create(2, 20, 'external-1', 'example.net', '2026-09-16 00:00:00');

        self::assertNotSame($first, $second);
        self::assertSame(1, $this->repository->findById($first)?->accountId);
        self::assertSame(2, $this->repository->findByProviderZone(20, 'external-1')?->accountId);
    }

    public function testExternalZoneIdIsUniqueWithinProviderAccount(): void
    {
        $this->repository->create(1, 10, 'external-1', 'example.org', '2026-09-16 00:00:00');

        $this->expectException(\Throwable::class);
        $this->repository->create(1, 10, 'external-1', 'other.example.org', '2026-09-16 00:00:00');
    }

    public function testProviderAccountMustBelongToTheManagedZoneAccount(): void
    {
        $this->expectException(\DomainException::class);
        $this->repository->create(1, 20, 'external-1', 'example.org', '2026-09-16 00:00:00');
    }

    public function testCountByAccountIdDoesNotHydrateZones(): void
    {
        $this->repository->create(1, 10, 'external-1', 'example.org', '2026-09-16 00:00:00');
        $this->repository->create(1, 10, 'external-2', 'example.net', '2026-09-16 00:00:00');
        $this->repository->create(2, 20, 'external-1', 'example.test', '2026-09-16 00:00:00');

        self::assertSame(2, $this->repository->countByAccountId(1));
        self::assertSame(1, $this->repository->countByAccountId(2));
        self::assertSame(0, $this->repository->countByAccountId(999));
    }
}

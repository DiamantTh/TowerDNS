<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use TowerDNS\Domain\Account\TeamRole;
use TowerDNS\Infrastructure\Persistence\DbalZoneMembershipRepository;

final class DbalZoneMembershipRepositoryTest extends TestCase
{
    private Connection $connection;
    private DbalZoneMembershipRepository $repository;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('CREATE TABLE managed_zones (id INTEGER PRIMARY KEY, account_id INTEGER NOT NULL)');
        $this->connection->executeStatement('CREATE TABLE zone_memberships (id INTEGER PRIMARY KEY AUTOINCREMENT, managed_zone_id INTEGER NOT NULL, user_id TEXT NOT NULL, role TEXT NOT NULL, granted_by TEXT NULL, created_at TEXT NOT NULL, UNIQUE(managed_zone_id, user_id))');
        $this->connection->executeStatement('INSERT INTO managed_zones (id, account_id) VALUES (1, 10), (2, 20)');
        $this->repository = new DbalZoneMembershipRepository($this->connection);
    }

    public function testMembershipsAreScopedByManagedZoneId(): void
    {
        $this->repository->grant(1, 'user-1', TeamRole::DNS_MANAGER, '2026-09-16 00:00:00');
        $this->repository->grant(2, 'user-1', TeamRole::VIEWER, '2026-09-16 00:00:00');

        self::assertSame(1, $this->repository->findMembership(1, 'user-1')?->managedZoneId);
        self::assertSame(2, $this->repository->findMembership(2, 'user-1')?->managedZoneId);
        self::assertCount(1, $this->repository->findByManagedZoneId(1));
    }

    public function testGrantIsUniquePerManagedZoneAndUser(): void
    {
        $this->repository->grant(1, 'user-1', TeamRole::VIEWER, '2026-09-16 00:00:00');
        $this->expectException(\Throwable::class);
        $this->repository->grant(1, 'user-1', TeamRole::DNS_MANAGER, '2026-09-16 00:00:00');
    }
}

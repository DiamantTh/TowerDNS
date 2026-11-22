<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use TowerDNS\Infrastructure\Persistence\DbalMonitoringRepository;

/**
 * @psalm-api Discovered by PHPUnit.
 * @psalm-suppress PropertyNotSetInConstructor PHPUnit initializes fixture state in setUp().
 */
final class DbalMonitoringRepositoryTest extends TestCase
{
    private Connection $connection;
    private DbalMonitoringRepository $repository;

    #[\Override]
    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('CREATE TABLE managed_zones (id INTEGER PRIMARY KEY, account_id INTEGER NOT NULL, provider_account_id INTEGER NOT NULL, canonical_name TEXT NOT NULL)');
        $this->connection->executeStatement('CREATE TABLE dns_monitoring_config (managed_zone_id INTEGER PRIMARY KEY, enabled BOOLEAN NOT NULL, smtp_dane_enabled BOOLEAN NOT NULL, interval_minutes INTEGER NOT NULL, updated_at TEXT NOT NULL, updated_by TEXT NULL)');
        $this->connection->executeStatement('CREATE TABLE dns_monitoring_state (managed_zone_id INTEGER NOT NULL, check_type TEXT NOT NULL, status TEXT NOT NULL, severity TEXT NOT NULL, method TEXT NOT NULL, summary_json TEXT NOT NULL, fingerprint TEXT NOT NULL, checked_at TEXT NOT NULL, changed_at TEXT NOT NULL, PRIMARY KEY (managed_zone_id, check_type))');
        $this->connection->executeStatement('CREATE TABLE dns_monitoring_events (id INTEGER PRIMARY KEY AUTOINCREMENT, managed_zone_id INTEGER NOT NULL, check_type TEXT NOT NULL, previous_status TEXT NULL, status TEXT NOT NULL, severity TEXT NOT NULL, fingerprint TEXT NOT NULL, occurred_at TEXT NOT NULL)');
        $this->connection->executeStatement("INSERT INTO managed_zones (id, account_id, provider_account_id, canonical_name) VALUES (5, 10, 20, 'example.test')");
        $this->repository = new DbalMonitoringRepository($this->connection);
    }

    public function testConfigurationIsOptInAndResultHistoryOnlyRecordsStateTransitions(): void
    {
        $this->repository->configure(5, true, true, 30, null, '2026-11-22 12:00:00');
        self::assertCount(1, $this->repository->listEnabled());

        $fingerprint = hash('sha256', 'secure');
        $args        = [5, 'smtp_dane', 'secure_records_published', 'info', 'trusted_https_resolver', ['scope' => 'dns_only_no_smtp_handshake'], $fingerprint, '2026-11-22 12:00:00', '2026-05-26 12:00:00'];
        self::assertTrue($this->repository->recordResult(...$args));
        self::assertFalse($this->repository->recordResult(...$args));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM dns_monitoring_events'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM dns_monitoring_state'));

        $this->repository->configure(5, false, false, 60, null, '2026-11-22 12:30:00');
        self::assertCount(0, $this->repository->listEnabled());
        self::assertSame(1, count($this->repository->overview()));
    }

    public function testInvalidIntervalsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->repository->configure(5, true, true, 1, null, '2026-11-22 12:00:00');
    }

    public function testOversizedSummariesAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->repository->recordResult(5, 'smtp_dane', 'indeterminate', 'unknown', 'resolver', ['oversize' => str_repeat('x', 9000)], hash('sha256', 'x'), '2026-11-22 12:00:00', '2026-05-26 12:00:00');
    }
}

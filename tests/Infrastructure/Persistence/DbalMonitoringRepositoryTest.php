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
        new \TowerDNS\Infrastructure\Persistence\SchemaManager($this->connection)->createTablesIfNotExist();
        $this->connection->executeStatement("INSERT INTO managed_zones (id, account_id, provider_account_id, canonical_name, provider_zone_id, created_at) VALUES (5, 10, 20, 'example.test', 'fixture', '2026-11-22 12:00:00')");
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

    public function testConfirmedAlertsAreDeduplicatedAndRecoveryIsQueued(): void
    {
        $this->repository->configure(5, true, true, 5, null, '2026-11-22 12:00:00');
        $alerts = new \TowerDNS\Infrastructure\Persistence\MonitoringAlerts($this->connection);
        $alerts->settings(5, 'ops@example.test', 'de-DE', 'warning', 2, 0, false);
        $observe = function (string $status, string $severity, string $time): void {
            $this->repository->recordResult(5, 'smtp_dane', $status, $severity, 'fixture', [], hash('sha256', $status), "2026-11-22 {$time}", '2026-05-26 12:00:00');
        };

        $observe('dnssec_bogus', 'critical', '12:05:00');
        $observe('dnssec_bogus', 'critical', '12:05:00');
        self::assertSame([], $alerts->deliveries(5));
        $observe('dnssec_bogus', 'critical', '12:10:00');
        $observe('dnssec_bogus', 'critical', '12:15:00');
        $observe('indeterminate', 'unknown', '12:20:00');
        self::assertSame('dnssec_bogus', $alerts->status(10, 5)['active_status'] ?? null);
        self::assertFalse($alerts->status(11, 5));

        $rows = $alerts->due('2026-11-22 12:20:00');
        self::assertCount(1, $rows);
        $key = (string) $rows[0]['event_key'];
        self::assertTrue($alerts->claim($key, '2026-11-22 12:20:00'));
        self::assertFalse($alerts->claim($key, '2026-11-22 12:20:00'));
        $alerts->finish($key, 'pending', 'smtp_connect', '2026-11-22 12:25:00');
        self::assertSame([], $alerts->due('2026-11-22 12:24:59'));
        self::assertTrue($alerts->claim($key, '2026-11-22 12:25:00'));
        $alerts->finish($key, 'unknown', 'smtp_delivery_uncertain', '2026-11-22 12:25:00');
        self::assertSame([], $alerts->due('2026-11-22 12:30:00'));

        $observe('secure_records_published', 'info', '12:25:00');
        $observe('secure_records_published', 'info', '12:30:00');
        self::assertNull($alerts->status(10, 5)['active_status'] ?? null);
        self::assertSame(['recovery', 'dnssec_bogus'], array_column($alerts->deliveries(5), 'kind'));
        self::assertArrayNotHasKey('recipient', $alerts->deliveries(5)[0]);
    }

    public function testMaintenanceSuppressesMailAndRecipientInjectionIsRejected(): void
    {
        $at = gmdate('Y-m-d H:i:s', time() - 600);
        $this->repository->configure(5, true, true, 5, null, $at);
        $alerts = new \TowerDNS\Infrastructure\Persistence\MonitoringAlerts($this->connection);
        $alerts->settings(5, 'ops@example.test', 'en-GB', 'warning', 2, 1, false);
        foreach ([$at, gmdate('Y-m-d H:i:s')] as $checkedAt) {
            $this->repository->recordResult(5, 'smtp_dane', 'indeterminate', 'unknown', 'fixture', [], hash('sha256', 'unknown'), $checkedAt, $at);
        }
        self::assertSame('indeterminate', $alerts->status(10, 5)['active_status'] ?? null);
        self::assertSame([], $alerts->deliveries(5));
        $this->expectException(\InvalidArgumentException::class);
        $alerts->settings(5, "ops@example.test\r\nBcc: other@example.test", 'en-GB', 'warning', 2, 0, false);
    }

    public function testNotificationCommandSendsOnceWithoutPersistingTransportErrorDetails(): void
    {
        $at = gmdate('Y-m-d H:i:s', time() - 600);
        $this->repository->configure(5, true, true, 5, null, $at);
        $alerts = new \TowerDNS\Infrastructure\Persistence\MonitoringAlerts($this->connection);
        $alerts->settings(5, 'ops@example.test', 'en-GB', 'warning', 2, 0, false);
        foreach ([$at, gmdate('Y-m-d H:i:s')] as $checkedAt) {
            $this->repository->recordResult(5, 'smtp_dane', 'indeterminate', 'unknown', 'fixture', [], hash('sha256', 'unknown'), $checkedAt, $at);
        }

        $transport = $this->createMock(\Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport::class);
        $transport->expects(self::exactly(2))->method('start')->willReturnCallback(static function (): void {
            static $first = true;
            if ($first) {
                $first = false;
                throw new \Symfony\Component\Mailer\Exception\TransportException('private fixture diagnostic');
            }
        });
        $transport->expects(self::once())->method('send')->with(self::callback(
            static fn(\Symfony\Component\Mime\Email $mail): bool => $mail->getTo()[0]->getAddress() === 'ops@example.test' && str_contains((string) $mail->getTextBody(), 'example.test')
        ));
        $command = new \Symfony\Component\Console\Tester\CommandTester(new \TowerDNS\Infrastructure\Console\MonitorNotifyCommand(
            $alerts,
            ['enabled' => true, 'from_address' => 'towerdns@example.test'],
            static fn(): \Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport => $transport,
        ));

        self::assertSame(1, $command->execute([]));
        self::assertStringNotContainsString('private fixture diagnostic', $command->getDisplay());
        self::assertSame('smtp_connect', $alerts->deliveries(5)[0]['failure']);
        $this->connection->update('dns_monitoring_deliveries', ['next_attempt_at' => $at], ['status' => 'pending']);
        self::assertSame(0, $command->execute([]));
        self::assertSame(0, $command->execute([]));
        self::assertSame('sent', $alerts->deliveries(5)[0]['status']);
    }

    public function testExpiryNotificationsAreScopedAndQueuedOnce(): void
    {
        $at     = '2026-11-24 00:00:00';
        $keys   = new \TowerDNS\Infrastructure\Persistence\DbalApiKeyRepository($this->connection);
        $expiry = new \DateTimeImmutable('2026-12-01T00:00:00Z');
        $keys->create('fixture', 'valid', hash('sha256', 'valid'), 'monitoring:read', 10, 5, null, $expiry);
        $keys->create('fixture', 'other account', hash('sha256', 'wrong'), 'monitoring:read', 11, 5, null, $expiry);
        $keys->create('fixture', 'revoked', hash('sha256', 'revoked'), 'monitoring:read', 10, 5, null, $expiry);
        $keys->revoke(3, 'fixture');
        $this->repository->configure(5, true, true, 5, null, $at);
        $alerts = new \TowerDNS\Infrastructure\Persistence\MonitoringAlerts($this->connection);
        $alerts->settings(5, 'ops@example.test', 'de-DE', 'warning', 2, 0, false);
        $alerts->queueExpiringKeys($at, '2026-12-08 00:00:00');
        $alerts->queueExpiringKeys($at, '2026-12-08 00:00:00');

        $rows = $alerts->due($at);
        self::assertCount(1, $rows);
        self::assertSame(1, (int) $rows[0]['api_key_id']);
        $keys->revoke(1, 'fixture');
        self::assertSame(0, (int) $alerts->due($at)[0]['active_key']);
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

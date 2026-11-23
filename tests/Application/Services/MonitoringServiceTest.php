<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Application\Services;

use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use TowerDNS\Application\Contracts\DnssecDiagnosticInterface;
use TowerDNS\Application\DTO\DnssecValidationResult;
use TowerDNS\Application\Repository\MonitoringRepositoryInterface;
use TowerDNS\Application\Services\MonitoringService;

/** @psalm-api Discovered by PHPUnit. */
final class MonitoringServiceTest extends TestCase
{
    public function testRepeatedStateIsStoredButOnlyTransitionsCreateEvents(): void
    {
        $repository = new class implements MonitoringRepositoryInterface {
            /** @var list<array<string, mixed>> */
            public array $zones = [['managed_zone_id' => 7, 'canonical_name' => 'example.test', 'smtp_dane_enabled' => true, 'interval_minutes' => 60]];
            /** @var list<string> */
            public array $fingerprints = [];

            #[\Override]
            public function listEnabled(int $limit = 250, ?int $managedZoneId = null): array
            {
                return $managedZoneId === null ? $this->zones : array_values(array_filter($this->zones, static fn(array $zone): bool => (int) $zone['managed_zone_id'] === $managedZoneId));
            }
            #[\Override]
            public function overview(int $limit = 250): array
            {
                return [];
            }
            #[\Override]
            public function history(int $managedZoneId, int $limit = 50): array
            {
                return [];
            }
            #[\Override]
            public function configure(int $managedZoneId, bool $enabled, bool $smtpDaneEnabled, int $intervalMinutes, ?string $actorUserId, string $updatedAt): void {}
            #[\Override]
            public function recordResult(int $managedZoneId, string $checkType, string $status, string $severity, string $method, array $summary, string $fingerprint, string $checkedAt, string $retentionCutoff): bool
            {
                $changed = !in_array($fingerprint, $this->fingerprints, true);
                if ($changed) {
                    $this->fingerprints[] = $fingerprint;
                }
                return $changed;
            }
        };
        $diagnostic = new class implements DnssecDiagnosticInterface {
            public int $calls = 0;
            #[\Override]
            public function validateTlsa(string $ownerFqdn, string $zoneName): DnssecValidationResult
            {
                throw new \LogicException();
            }
            #[\Override]
            public function checkSmtpDane(string $domain): array
            {
                ++$this->calls;
                return ['status' => 'secure', 'method' => 'trusted_https_resolver', 'mx_dnssec' => 'secure', 'mx' => [['preference' => 10, 'host' => 'mx.example.test', 'dnssec' => 'secure', 'tlsa_status' => 'published', 'ttl' => 60]]];
            }
        };
        $clock = new readonly class implements ClockInterface {
            #[\Override]
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-11-22T12:00:00+00:00');
            }
        };
        $service = new MonitoringService($repository, $diagnostic, $clock);

        $first  = $service->runDue();
        $second = $service->runDue(force: true);

        self::assertSame(1, $first['checked']);
        self::assertSame('secure_records_published', $first['results'][0]['status']);
        self::assertSame('info', $first['results'][0]['severity']);
        self::assertSame(1, $first['changed']);
        self::assertSame(0, $second['changed']);
        self::assertSame(2, $diagnostic->calls);
    }

    public function testDnssecBogusAndFailedExecutionRemainDistinctStates(): void
    {
        $repository = new class implements MonitoringRepositoryInterface {
            /** @var list<array<string, mixed>> */
            public array $zones = [
                ['managed_zone_id' => 1, 'canonical_name' => 'bogus.test', 'smtp_dane_enabled' => true, 'interval_minutes' => 60],
                ['managed_zone_id' => 2, 'canonical_name' => 'error.test', 'smtp_dane_enabled' => true, 'interval_minutes' => 60],
            ];
            /** @var list<array<string, mixed>> */
            public array $results = [];
            #[\Override]
            public function listEnabled(int $limit = 250, ?int $managedZoneId = null): array
            {
                return $managedZoneId === null ? $this->zones : array_values(array_filter($this->zones, static fn(array $zone): bool => (int) $zone['managed_zone_id'] === $managedZoneId));
            }
            #[\Override]
            public function overview(int $limit = 250): array
            {
                return [];
            }
            #[\Override]
            public function history(int $managedZoneId, int $limit = 50): array
            {
                return [];
            }
            #[\Override]
            public function configure(int $managedZoneId, bool $enabled, bool $smtpDaneEnabled, int $intervalMinutes, ?string $actorUserId, string $updatedAt): void {}
            #[\Override]
            public function recordResult(int $managedZoneId, string $checkType, string $status, string $severity, string $method, array $summary, string $fingerprint, string $checkedAt, string $retentionCutoff): bool
            {
                $this->results[] = ['zone' => $managedZoneId, 'status' => $status, 'severity' => $severity, 'summary' => $summary];
                return true;
            }
        };
        $diagnostic = new class implements DnssecDiagnosticInterface {
            #[\Override]
            public function validateTlsa(string $ownerFqdn, string $zoneName): DnssecValidationResult
            {
                throw new \LogicException();
            }
            #[\Override]
            public function checkSmtpDane(string $domain): array
            {
                if ($domain === 'error.test') {
                    throw new \RuntimeException('Resolver details must not be persisted.');
                }
                return ['status' => 'bogus', 'method' => 'trusted_https_resolver', 'mx' => []];
            }
        };
        $clock = new readonly class implements ClockInterface {
            #[\Override]
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-11-22T12:00:00+00:00');
            }
        };
        $result = new MonitoringService($repository, $diagnostic, $clock)->runDue();

        self::assertSame(2, $result['checked']);
        self::assertSame(1, $result['failures']);
        self::assertSame('dnssec_bogus', $repository->results[0]['status']);
        self::assertSame('critical', $repository->results[0]['severity']);
        self::assertSame('check_execution_failed', $repository->results[1]['status']);
        self::assertSame('unknown', $repository->results[1]['severity']);
        self::assertSame(['scope' => 'dns_only_no_smtp_handshake', 'reason' => 'diagnostic_request_failed'], $repository->results[1]['summary']);
    }

    public function testDisabledChecksAndNotYetDueChecksAreSkipped(): void
    {
        $repository = new class implements MonitoringRepositoryInterface {
            /** @var list<array<string, mixed>> */
            public array $zones = [
                ['managed_zone_id' => 1, 'canonical_name' => 'disabled.test', 'smtp_dane_enabled' => false, 'interval_minutes' => 60],
                ['managed_zone_id' => 2, 'canonical_name' => 'due-later.test', 'smtp_dane_enabled' => true, 'interval_minutes' => 60, 'checked_at' => '2026-11-22 11:30:00'],
            ];
            #[\Override]
            public function listEnabled(int $limit = 250, ?int $managedZoneId = null): array
            {
                return $managedZoneId === null ? $this->zones : array_values(array_filter($this->zones, static fn(array $zone): bool => (int) $zone['managed_zone_id'] === $managedZoneId));
            }
            #[\Override]
            public function overview(int $limit = 250): array
            {
                return [];
            }
            #[\Override]
            public function history(int $managedZoneId, int $limit = 50): array
            {
                return [];
            }
            #[\Override]
            public function configure(int $managedZoneId, bool $enabled, bool $smtpDaneEnabled, int $intervalMinutes, ?string $actorUserId, string $updatedAt): void {}
            #[\Override]
            public function recordResult(int $managedZoneId, string $checkType, string $status, string $severity, string $method, array $summary, string $fingerprint, string $checkedAt, string $retentionCutoff): bool
            {
                return true;
            }
        };
        $diagnostic = new class implements DnssecDiagnosticInterface {
            #[\Override]
            public function validateTlsa(string $ownerFqdn, string $zoneName): DnssecValidationResult
            {
                throw new \LogicException();
            }
            #[\Override]
            public function checkSmtpDane(string $domain): array
            {
                throw new \LogicException('No check should be run.');
            }
        };
        $clock = new readonly class implements ClockInterface {
            #[\Override]
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-11-22T12:00:00+00:00');
            }
        };

        self::assertSame(0, new MonitoringService($repository, $diagnostic, $clock)->runDue()['checked']);
    }

    public function testPartialMxTlsaCoverageIsNotReportedAsCompleteCoverage(): void
    {
        $repository = $this->createMock(MonitoringRepositoryInterface::class);
        $repository->method('listEnabled')->willReturn([[
            'managed_zone_id'   => 10,
            'canonical_name'    => 'mail.example.test',
            'smtp_dane_enabled' => true,
            'interval_minutes'  => 60,
        ]]);
        $repository->expects(self::once())->method('recordResult')->with(
            10,
            'smtp_dane',
            'secure_partial_tlsa',
            'warning',
            self::anything(),
            self::anything(),
            self::anything(),
            self::anything(),
            self::anything(),
        )->willReturn(true);
        $diagnostic = $this->createMock(DnssecDiagnosticInterface::class);
        $diagnostic->method('checkSmtpDane')->willReturn([
            'status' => 'secure',
            'method' => 'trusted_https_resolver',
            'mx'     => [
                ['preference' => 10, 'host' => 'mx1.example.test', 'dnssec' => 'secure', 'tlsa_status' => 'published', 'ttl' => 300],
                ['preference' => 20, 'host' => 'mx2.example.test', 'dnssec' => 'secure', 'tlsa_status' => 'no_tlsa_records', 'ttl' => 300],
            ],
        ]);
        $clock = new readonly class implements ClockInterface {
            #[\Override]
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-11-22T12:00:00+00:00');
            }
        };

        $result = new MonitoringService($repository, $diagnostic, $clock)->runDue();

        self::assertSame('secure_partial_tlsa', $result['results'][0]['status']);
        self::assertSame('warning', $result['results'][0]['severity']);
    }

    public function testUnconfiguredResolverIsKeptAsNotChecked(): void
    {
        $repository = $this->createMock(MonitoringRepositoryInterface::class);
        $repository->method('listEnabled')->willReturn([[
            'managed_zone_id'   => 11,
            'canonical_name'    => 'mail.example.test',
            'smtp_dane_enabled' => true,
            'interval_minutes'  => 60,
        ]]);
        $repository->expects(self::once())->method('recordResult')->with(
            11,
            'smtp_dane',
            'not_checked',
            'unknown',
            self::anything(),
            self::anything(),
            self::anything(),
            self::anything(),
            self::anything(),
        )->willReturn(true);
        $diagnostic = $this->createMock(DnssecDiagnosticInterface::class);
        $diagnostic->method('checkSmtpDane')->willReturn(['status' => 'not_checked', 'method' => 'none', 'mx' => []]);
        $clock = new readonly class implements ClockInterface {
            #[\Override]
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-11-22T12:00:00+00:00');
            }
        };

        $result = new MonitoringService($repository, $diagnostic, $clock)->runDue();

        self::assertSame('not_checked', $result['results'][0]['status']);
        self::assertSame(1, $result['failures']);
    }

    public function testAuthoritativeChecksAllowExtraValuesAndDetectDivergence(): void
    {
        $value      = '3 1 1 ' . str_repeat('a', 64);
        $extra      = '3 1 1 ' . str_repeat('b', 64);
        $repository = $this->createStub(MonitoringRepositoryInterface::class);
        $repository->method('listEnabled')->willReturn([[
            'managed_zone_id'  => 7, 'canonical_name' => 'example.test', 'smtp_dane_enabled' => true,
            'interval_minutes' => 60, 'tlsa_owner' => '_443._tcp', 'tlsa_expected' => $value,
        ]]);
        $diagnostic = $this->createStub(DnssecDiagnosticInterface::class);
        $diagnostic->method('checkSmtpDane')->willReturn(['status' => 'secure', 'mx' => []]);
        $first = ['nameserver' => 'ns1.example.test', 'address' => null, 'status' => 'has_unexpected_records',
            'rcode'            => 'NOERROR', 'authoritative' => true, 'records' => [$value, $extra], 'minimum_ttl' => 300, 'checked_at' => '2026-11-24T00:00:00Z'];
        $second  = [...$first, 'nameserver' => 'ns2.example.test', 'status' => 'nodata', 'records' => []];
        $checker = $this->createMock(\TowerDNS\Application\Contracts\AuthoritativeTlsaCheckerInterface::class);
        $checker->expects(self::exactly(2))->method('check')->with('_443._tcp.example.test', 'example.test', [$value], null)->willReturnOnConsecutiveCalls(
            new \TowerDNS\Application\DTO\AuthoritativeTlsaCheck('_443._tcp.example.test', 'records_differ', '2026-11-24T00:00:00Z', 'fixture', [$value], [$first], null, 'direct_authoritative_nameserver_queries'),
            new \TowerDNS\Application\DTO\AuthoritativeTlsaCheck('_443._tcp.example.test', 'records_differ', '2026-11-24T00:00:00Z', 'fixture', [$value], [$first, $second], null, 'direct_authoritative_nameserver_queries'),
        );
        $service = new MonitoringService($repository, $diagnostic, new \TowerDNS\Infrastructure\Clock\SystemClock(), $checker);

        self::assertSame('secure_no_mx', $service->runDue()['results'][0]['status']);
        self::assertSame('authoritative_divergent', $service->runDue()['results'][0]['status']);
    }
}

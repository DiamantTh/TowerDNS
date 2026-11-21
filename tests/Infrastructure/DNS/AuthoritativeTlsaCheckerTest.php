<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\DNS;

use PHPUnit\Framework\TestCase;
use TowerDNS\Infrastructure\DNS\AuthoritativeTlsaChecker;
use TowerDNS\Infrastructure\DNS\DnsWireResponse;

/** @psalm-api Runtime discovery by PHPUnit is not statically visible. */
final class AuthoritativeTlsaCheckerTest extends TestCase
{
    public function testReportsAllAuthoritativeAddressesMatchingExpectedRecords(): void
    {
        $records = ['3 1 1 ' . str_repeat('a', 64)];
        $checker = new AuthoritativeTlsaChecker(
            static fn(string $name, int $type): array => match (true) {
                $type === DNS_NS            => [['target' => 'ns1.example.net'], ['target' => 'ns2.example.net']],
                $name === 'ns1.example.net' => [['ip' => '8.8.8.8'], ['ipv6' => '2001:4860:4860::8888']],
                $name === 'ns2.example.net' => [['ip' => '1.1.1.1']],
                default                     => [],
            },
            static function (string $address, string $owner, float $timeout) use ($records): DnsWireResponse {
                self::assertSame('_443._tcp.example.org', $owner);
                self::assertGreaterThan(0, $timeout);
                self::assertContains($address, ['8.8.8.8', '2001:4860:4860::8888', '1.1.1.1']);
                return new DnsWireResponse(0, true, false, $records, 300);
            },
        );

        $check = $checker->check('_443._tcp.example.org', 'example.org', $records, 300);

        self::assertSame('all_match', $check->status);
        self::assertCount(3, $check->servers);
        self::assertSame(['8.8.8.8', '2001:4860:4860::8888', '1.1.1.1'], array_column($check->servers, 'address'));
        self::assertSame('not_validated', $check->toArray()['dnssec']);
    }

    public function testReportsDisagreementWhenNameserversServeDifferentTlsaSets(): void
    {
        $expected = ['3 1 1 ' . str_repeat('a', 64)];
        $extra    = '3 1 1 ' . str_repeat('b', 64);
        $checker  = new AuthoritativeTlsaChecker(
            static fn(string $name, int $type): array => $type === DNS_NS
                ? [['target' => 'ns1.example.net'], ['target' => 'ns2.example.net']]
                : [['ip' => $name === 'ns1.example.net' ? '8.8.8.8' : '1.1.1.1']],
            static function (string $address, string $owner, float $timeout) use ($expected, $extra): DnsWireResponse {
                self::assertSame('_443._tcp.example.org', $owner);
                self::assertGreaterThan(0, $timeout);
                return new DnsWireResponse(0, true, false, $address === '8.8.8.8' ? $expected : [$expected[0], $extra], 300);
            },
        );

        $check = $checker->check('_443._tcp.example.org', 'example.org', $expected, 300);

        self::assertSame('nameserver_disagreement', $check->status);
        self::assertSame(['matches', 'has_unexpected_records'], array_column($check->servers, 'status'));
    }

    public function testDoesNotReportSuccessWhenADelegatedNameserverCannotBeResolved(): void
    {
        $expected = ['3 1 1 ' . str_repeat('a', 64)];
        $checker  = new AuthoritativeTlsaChecker(
            static fn(string $name, int $type): array => match (true) {
                $type === DNS_NS            => [['target' => 'ns1.example.net'], ['target' => 'ns2.example.net']],
                $name === 'ns1.example.net' => [['ip' => '8.8.8.8']],
                default                     => [],
            },
            static function (string $address, string $owner, float $timeout) use ($expected): DnsWireResponse {
                self::assertSame('_443._tcp.example.org', $owner);
                self::assertGreaterThan(0, $timeout);
                self::assertSame('8.8.8.8', $address);
                return new DnsWireResponse(0, true, false, $expected, 300);
            },
        );

        $check = $checker->check('_443._tcp.example.org', 'example.org', $expected, 300);

        self::assertSame('incomplete', $check->status);
        self::assertCount(1, $check->servers);
    }

    public function testCanConfirmAnEmptyExpectedRrsetAndDetectLeftoverPublicRecords(): void
    {
        $checker = new AuthoritativeTlsaChecker(
            static fn(string $name, int $type): array => $type === DNS_NS
                ? [['target' => 'ns1.example.net']]
                : [['ip' => '8.8.8.8']],
            static function (string $address, string $owner, float $timeout): DnsWireResponse {
                self::assertSame('_443._tcp.example.org', $owner);
                self::assertGreaterThan(0, $timeout);
                self::assertSame('8.8.8.8', $address);
                return new DnsWireResponse(0, true, false, [], null);
            },
        );

        $empty = $checker->check('_443._tcp.example.org', 'example.org', [], null);
        self::assertSame('all_empty', $empty->status);

        $withLeftovers = new AuthoritativeTlsaChecker(
            static fn(string $name, int $type): array => $type === DNS_NS
                ? [['target' => 'ns1.example.net']]
                : [['ip' => '8.8.8.8']],
            static function (string $address, string $owner, float $timeout): DnsWireResponse {
                self::assertSame('_443._tcp.example.org', $owner);
                self::assertGreaterThan(0, $timeout);
                self::assertSame('8.8.8.8', $address);
                return new DnsWireResponse(0, true, false, ['3 1 1 ' . str_repeat('a', 64)], 300);
            },
        );
        self::assertSame('records_differ', $withLeftovers->check('_443._tcp.example.org', 'example.org', [], null)->status);
    }
}

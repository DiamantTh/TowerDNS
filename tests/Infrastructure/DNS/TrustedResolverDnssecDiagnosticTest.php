<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\DNS;

use PHPUnit\Framework\TestCase;
use TowerDNS\Infrastructure\DNS\TrustedResolverDnssecDiagnostic;

/** @psalm-api PHPUnit discovers this test at runtime. */
final class TrustedResolverDnssecDiagnosticTest extends TestCase
{
    public function testUnconfiguredResolverDoesNotClaimValidation(): void
    {
        self::assertSame('not_checked', new TrustedResolverDnssecDiagnostic()->validateTlsa('_443._tcp.example.test', 'example.test')->status);
    }

    public function testResolverUrlWithEmbeddedCredentialsOrFragmentIsRejectedWithoutNetworkAccess(): void
    {
        foreach ([
            'https://user@resolver.example.test/dns-query',
            'https://resolver.example.test/dns-query#fragment',
        ] as $endpoint) {
            self::assertSame('not_checked', new TrustedResolverDnssecDiagnostic($endpoint)->validateTlsa('_443._tcp.example.test', 'example.test')->status);
        }
    }

    public function testAuthenticatedTlsaAnswerIsSecure(): void
    {
        $diagnostic = new TrustedResolverDnssecDiagnostic('https://resolver.example.test/dns-query', static fn(string $name, int $type, int $id): string => self::response($name, $type, $id, 0x81a0, $type === 43 ? [self::rr($name, 43, "\x00\x01\x08\x02\0\0\0\0")] : []));

        $result = $diagnostic->validateTlsa('_443._tcp.example.test', 'example.test');

        self::assertSame('secure', $result->status);
        self::assertSame('trusted_https_resolver_ad', $result->method);
    }

    public function testAuthenticatedAbsenceOfDsCanEstablishInsecureDelegation(): void
    {
        $diagnostic = new TrustedResolverDnssecDiagnostic('https://resolver.example.test/dns-query', static fn(string $name, int $type, int $id): string => self::response($name, $type, $id, $type === 43 ? 0x81a0 : 0x8180));

        self::assertSame('insecure', $diagnostic->validateTlsa('_443._tcp.example.test', 'example.test')->status);
    }

    public function testResolverEdeBogusIsDistinctFromAmbiguousServfail(): void
    {
        $bogus   = new TrustedResolverDnssecDiagnostic('https://resolver.example.test/dns-query', static fn(string $name, int $type, int $id): string => self::response($name, $type, $id, $type === 52 ? 0x8182 : 0x81a0, [], $type === 52 ? [6] : []));
        $unknown = new TrustedResolverDnssecDiagnostic('https://resolver.example.test/dns-query', static fn(string $name, int $type, int $id): string => self::response($name, $type, $id, $type === 52 ? 0x8182 : 0x81a0));

        self::assertSame('bogus', $bogus->validateTlsa('_443._tcp.example.test', 'example.test')->status);
        self::assertSame('indeterminate', $unknown->validateTlsa('_443._tcp.example.test', 'example.test')->status);
    }

    public function testSmtpDnsDiagnosticFollowsMxNamesAndDoesNotClaimHandshake(): void
    {
        $diagnostic = new TrustedResolverDnssecDiagnostic('https://resolver.example.test/dns-query', static function (string $name, int $type, int $id): string {
            if ($type === 15) {
                $exchange = self::wireName('mx.example.test');
                return self::response($name, $type, $id, 0x81a0, [self::rr($name, 15, pack('n', 10) . $exchange)]);
            }
            if ($type === 43) {
                return self::response($name, $type, $id, 0x81a0, [self::rr($name, 43, "\0\1\x08\x02\0\0\0\0")]);
            }
            return self::response($name, $type, $id, 0x81a0, [self::rr($name, 52, "\x03\x01\x01" . str_repeat("\x01", 32))]);
        });

        $result = $diagnostic->checkSmtpDane('example.test');

        self::assertSame('secure', $result['status']);
        self::assertSame('not_tested', $result['dane']);
        self::assertSame('not_tested', $result['smtp_tls']);
        self::assertSame('mx.example.test', $result['mx'][0]['host']);
        self::assertSame('published', $result['mx'][0]['tlsa_status']);
    }

    /**
     * @param list<string> $answers
     * @param list<int> $edeCodes
     */
    private static function response(string $name, int $type, int $id, int $flags, array $answers = [], array $edeCodes = []): string
    {
        $wireName = self::wireName($name);
        $packet   = pack('nnnnnn', $id, $flags, 1, count($answers), 0, $edeCodes === [] ? 0 : 1)
            . $wireName . pack('nn', $type, 1) . implode('', $answers);
        if ($edeCodes !== []) {
            $options = '';
            foreach ($edeCodes as $code) {
                $options .= pack('nnn', 15, 2, $code);
            }
            $packet .= "\0" . pack('nnNn', 41, 1232, 0, strlen($options)) . $options;
        }
        return $packet;
    }

    private static function rr(string $owner, int $type, string $rdata): string
    {
        return self::wireName($owner) . pack('nnNn', $type, 1, 300, strlen($rdata)) . $rdata;
    }

    private static function wireName(string $name): string
    {
        $result = '';
        foreach (explode('.', $name) as $label) {
            $result .= chr(strlen($label)) . $label;
        }
        return $result . "\0";
    }
}

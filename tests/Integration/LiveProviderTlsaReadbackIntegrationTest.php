<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Integration;

use PHPUnit\Framework\TestCase;
use TowerDNS\Application\Contracts\Capability;
use TowerDNS\Application\Contracts\RrsetProviderInterface;
use TowerDNS\Application\DNS\RdataCanonicalizer;
use TowerDNS\Application\Module\LocalModuleDiscovery;
use TowerDNS\Application\Module\ProviderModuleRegistry;
use TowerDNS\Application\Validation\DNSNameValidator;
use TowerDNS\Domain\DNS\DNSRecordType;
use TowerDNS\Infrastructure\Provider\DNSProviderFactory;

/** Optional, read-only live provider check. Never writes to the configured zone. */
/** @psalm-api Runtime discovery by PHPUnit is not statically visible. */
final class LiveProviderTlsaReadbackIntegrationTest extends TestCase
{
    public function testLiveProviderReturnsConfiguredDedicatedTlsaRrset(): void
    {
        $required = [
            'TOWERDNS_LIVE_DNS_PROVIDER', 'TOWERDNS_LIVE_DNS_CREDENTIALS_JSON', 'TOWERDNS_LIVE_DNS_ZONE_ID',
            'TOWERDNS_LIVE_DNS_ZONE_NAME', 'TOWERDNS_LIVE_DNS_TLSA_OWNER', 'TOWERDNS_LIVE_DNS_EXPECTED_TLSA_JSON',
            'TOWERDNS_LIVE_DNS_READ_ONLY_CONFIRMATION',
        ];
        $values = [];
        foreach ($required as $name) {
            $value = getenv($name);
            if (!is_string($value) || $value === '') {
                self::markTestSkipped('Set all TOWERDNS_LIVE_DNS_* values to run this read-only live provider test.');
            }
            $values[$name] = $value;
        }
        self::assertSame('I_AUTHORIZE_READ_ONLY_TEST_ZONE', $values['TOWERDNS_LIVE_DNS_READ_ONLY_CONFIRMATION']);

        $credentials = json_decode($values['TOWERDNS_LIVE_DNS_CREDENTIALS_JSON'], true, 32, JSON_THROW_ON_ERROR);
        $expected    = json_decode($values['TOWERDNS_LIVE_DNS_EXPECTED_TLSA_JSON'], true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($credentials);
        self::assertIsArray($expected);
        self::assertTrue(array_is_list($expected));
        self::assertNotSame([], $expected);
        self::assertLessThanOrEqual(32, count($expected));
        foreach ($credentials as $value) {
            self::assertIsString($value, 'Credentials must be supplied as a JSON object of strings.');
        }
        foreach ($expected as $value) {
            self::assertIsString($value);
        }

        $discovery = new LocalModuleDiscovery(dirname(__DIR__, 2) . '/modules');
        $provider  = new DNSProviderFactory(new ProviderModuleRegistry($discovery->providerModules()))
            ->build($values['TOWERDNS_LIVE_DNS_PROVIDER'], $credentials);
        self::assertTrue($provider->capabilities()->supports(Capability::RECORD_LIST));
        self::assertInstanceOf(RrsetProviderInterface::class, $provider);

        $zoneName = DNSNameValidator::normalise($values['TOWERDNS_LIVE_DNS_ZONE_NAME']);
        $owner    = DNSNameValidator::normaliseRecordOwner($values['TOWERDNS_LIVE_DNS_TLSA_OWNER'], $zoneName);
        self::assertNotSame('', $owner, 'Use a dedicated TLSA service owner inside the disposable test zone.');
        self::assertStringContainsString('phase9a-live-test', $owner, 'The owner must be explicitly marked as the Phase 9A test owner.');

        $rrset = array_find($provider->listRrsets($values['TOWERDNS_LIVE_DNS_ZONE_ID']), fn($candidate): bool => $candidate->type->presentation === 'TLSA'
            && DNSNameValidator::normaliseRecordOwner($candidate->ownerName, $zoneName)                                                         === $owner);
        self::assertNotNull($rrset, 'The configured provider did not return the explicitly configured TLSA RRset.');

        $type     = DNSRecordType::parse('TLSA');
        $actual   = array_values(array_unique(array_map(static fn(string $rdata): string => RdataCanonicalizer::canonicalize($type, $rdata), $rrset->rdata)));
        $expected = array_values(array_unique(array_map(static fn(string $rdata): string => RdataCanonicalizer::canonicalize($type, $rdata), $expected)));
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        self::assertSame($expected, $actual, 'Live provider TLSA readback differs from the configured disposable-zone expectation.');
    }
}

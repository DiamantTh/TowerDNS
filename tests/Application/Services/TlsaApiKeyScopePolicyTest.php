<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Application\Services;

use PHPUnit\Framework\TestCase;
use TowerDNS\Application\Services\TlsaApiKeyScopePolicy;

/** @psalm-api PHPUnit discovers these tests at runtime. */
final class TlsaApiKeyScopePolicyTest extends TestCase
{
    public function testTargetedAppendProvidersCanIssueAppendAndWriteKeys(): void
    {
        $policy = new TlsaApiKeyScopePolicy();

        self::assertTrue($policy->supports('tlsa:append', false, true, true));
        self::assertTrue($policy->supports('tlsa:write', false, true, true));
    }

    public function testWriteScopeRequiresBothTargetedOperations(): void
    {
        $policy = new TlsaApiKeyScopePolicy();

        self::assertTrue($policy->supports('tlsa:append', false, true, false));
        self::assertFalse($policy->supports('tlsa:write', false, true, false));
        self::assertFalse($policy->supports('tlsa:append', false, false, true));
        self::assertFalse($policy->supports('tlsa:write', false, false, true));
    }

    public function testConditionalProviderCanIssueDeltaScopesAndReadScopeIsAlwaysAvailable(): void
    {
        $policy = new TlsaApiKeyScopePolicy();
        self::assertTrue($policy->supports('tlsa:read', false, false, false));
        self::assertTrue($policy->supports('tlsa:append', true, false, false));
        self::assertTrue($policy->supports('tlsa:write', true, false, false));
        self::assertFalse($policy->supports('other:scope', true, false, false));
    }
}

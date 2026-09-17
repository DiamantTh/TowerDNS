<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Application\Validation;

use PHPUnit\Framework\TestCase;
use TowerDNS\Application\Validation\ProviderEndpointPolicy;

final class ProviderEndpointPolicyTest extends TestCase
{
    public function testAllowsPublicHttpsIpLiteral(): void
    {
        $this->expectNotToPerformAssertions();
        ProviderEndpointPolicy::assertAllowed('https://1.1.1.1/api', false);
    }

    public function testRejectsLoopbackIpLiteral(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ProviderEndpointPolicy::assertAllowed('https://127.0.0.1/api', false);
    }

    public function testRejectsPrivateRangeIpLiteral(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ProviderEndpointPolicy::assertAllowed('https://10.0.0.5/api', false);
    }

    public function testRejectsLinkLocalIpLiteral(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ProviderEndpointPolicy::assertAllowed('https://169.254.1.1/api', false);
    }

    public function testRejectsPlaintextHttpWithoutOptIn(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ProviderEndpointPolicy::assertAllowed('http://1.1.1.1/api', false);
    }

    public function testAllowsPrivateRangeWithExplicitOptIn(): void
    {
        $this->expectNotToPerformAssertions();
        ProviderEndpointPolicy::assertAllowed('http://10.0.0.5/api', true);
    }

    public function testRejectsUnsupportedScheme(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ProviderEndpointPolicy::assertAllowed('ftp://example.com/api', false);
    }

    public function testRejectsMalformedUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ProviderEndpointPolicy::assertAllowed('not-a-url', false);
    }

    public function testAssertCredentialsSafeIgnoresMissingBaseUrl(): void
    {
        $this->expectNotToPerformAssertions();
        ProviderEndpointPolicy::assertCredentialsSafe(['username' => 'foo']);
    }

    public function testAssertCredentialsSafeRejectsUnsafeBaseUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ProviderEndpointPolicy::assertCredentialsSafe(['base_url' => 'http://192.168.1.1/api']);
    }

    public function testAssertCredentialsSafeHonoursOptInFlag(): void
    {
        $this->expectNotToPerformAssertions();
        ProviderEndpointPolicy::assertCredentialsSafe([
            'base_url'              => 'http://192.168.1.1/api',
            'allow_private_network' => '1',
        ]);
    }
}

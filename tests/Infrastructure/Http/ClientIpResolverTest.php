<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Http;

use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\TestCase;
use TowerDNS\Infrastructure\Http\ClientIpResolver;

final class ClientIpResolverTest extends TestCase
{
    public function testUsesRemoteAddrWhenNoTrustedProxiesConfigured(): void
    {
        $resolver = new ClientIpResolver([]);
        $request  = $this->request('203.0.113.9', ['X-Forwarded-For' => '198.51.100.1']);

        // No trusted proxies configured — X-Forwarded-For must be ignored
        // entirely, since it is fully attacker-controlled.
        self::assertSame('203.0.113.9', $resolver->resolve($request));
    }

    public function testIgnoresForwardedForWhenRemoteAddrIsNotTrusted(): void
    {
        $resolver = new ClientIpResolver(['10.0.0.1']);
        $request  = $this->request('203.0.113.9', ['X-Forwarded-For' => '198.51.100.1']);

        self::assertSame('203.0.113.9', $resolver->resolve($request));
    }

    public function testUsesForwardedForWhenRemoteAddrIsTrustedProxy(): void
    {
        $resolver = new ClientIpResolver(['10.0.0.1']);
        $request  = $this->request('10.0.0.1', ['X-Forwarded-For' => '198.51.100.1']);

        self::assertSame('198.51.100.1', $resolver->resolve($request));
    }

    public function testSkipsTrustedHopsInForwardedForChain(): void
    {
        $resolver = new ClientIpResolver(['10.0.0.1', '10.0.0.2']);
        // Chain as appended by two trusted proxies: client, proxy1, proxy2 (closest last).
        $request = $this->request('10.0.0.2', ['X-Forwarded-For' => '198.51.100.1, 10.0.0.1']);

        self::assertSame('198.51.100.1', $resolver->resolve($request));
    }

    public function testSupportsCidrRanges(): void
    {
        $resolver = new ClientIpResolver(['10.0.0.0/8']);
        $request  = $this->request('10.1.2.3', ['X-Forwarded-For' => '198.51.100.1']);

        self::assertSame('198.51.100.1', $resolver->resolve($request));
    }

    public function testFallsBackToRemoteAddrWhenForwardedForIsMissing(): void
    {
        $resolver = new ClientIpResolver(['10.0.0.1']);
        $request  = $this->request('10.0.0.1', []);

        self::assertSame('10.0.0.1', $resolver->resolve($request));
    }

    /** @param array<string, string> $headers */
    private function request(string $remoteAddr, array $headers): ServerRequest
    {
        $request = new ServerRequest(serverParams: ['REMOTE_ADDR' => $remoteAddr]);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        return $request;
    }
}

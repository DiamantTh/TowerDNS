<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Http\Middleware;

use Laminas\Diactoros\Response;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Infrastructure\Http\ClientIpResolver;
use TowerDNS\Infrastructure\Http\Middleware\ClientIpMiddleware;

final class ClientIpMiddlewareTest extends TestCase
{
    public function testStoresRemoteAddrWhenNoTrustedProxiesConfigured(): void
    {
        $middleware = new ClientIpMiddleware(new ClientIpResolver([]));
        $request    = $this->request('203.0.113.9', ['X-Forwarded-For' => '198.51.100.1']);

        $captured = new \stdClass();
        $middleware->process($request, $this->captureHandler($captured));

        self::assertSame('203.0.113.9', $captured->ip ?? null);
    }

    public function testStoresResolvedIpWhenBehindTrustedProxy(): void
    {
        $middleware = new ClientIpMiddleware(new ClientIpResolver(['10.0.0.1']));
        $request    = $this->request('10.0.0.1', ['X-Forwarded-For' => '198.51.100.1']);

        $captured = new \stdClass();
        $middleware->process($request, $this->captureHandler($captured));

        self::assertSame('198.51.100.1', $captured->ip ?? null);
    }

    public function testIgnoresForwardedForFromUntrustedRemoteAddr(): void
    {
        $middleware = new ClientIpMiddleware(new ClientIpResolver(['10.0.0.1']));
        $request    = $this->request('203.0.113.9', ['X-Forwarded-For' => '198.51.100.1']);

        $captured = new \stdClass();
        $middleware->process($request, $this->captureHandler($captured));

        self::assertSame('203.0.113.9', $captured->ip ?? null);
    }

    private function captureHandler(\stdClass $captured): RequestHandlerInterface
    {
        return new readonly class ($captured) implements RequestHandlerInterface {
            public function __construct(private \stdClass $captured) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->captured->ip = $request->getAttribute(ClientIpResolver::ATTRIBUTE);
                return new Response();
            }
        };
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

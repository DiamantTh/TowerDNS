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
use TowerDNS\Infrastructure\Http\Middleware\ForceHttpsMiddleware;

final class ForceHttpsMiddlewareTest extends TestCase
{
    public function testPassesThroughWhenDisabled(): void
    {
        $middleware = new ForceHttpsMiddleware(false, new ClientIpResolver([]));
        $request    = $this->request('http', '203.0.113.9');

        $response = $middleware->process($request, $this->passThroughHandler());

        self::assertSame(200, $response->getStatusCode());
    }

    public function testPassesThroughForDirectHttpsRequest(): void
    {
        $middleware = new ForceHttpsMiddleware(true, new ClientIpResolver([]));
        $request    = $this->request('https', '203.0.113.9');

        $response = $middleware->process($request, $this->passThroughHandler());

        self::assertSame(200, $response->getStatusCode());
    }

    public function testRedirectsAPlainHttpGetRequestToHttps(): void
    {
        $middleware = new ForceHttpsMiddleware(true, new ClientIpResolver([]));
        $request    = $this->request('http', '203.0.113.9')
            ->withUri(new \Laminas\Diactoros\Uri('http://towerdns.example/accounts?x=1'));

        $response = $middleware->process($request, $this->failIfCalledHandler());

        self::assertSame(301, $response->getStatusCode());
        self::assertSame('https://towerdns.example/accounts?x=1', $response->getHeaderLine('Location'));
    }

    public function testRejectsAPlainHttpPostRequestInsteadOfRedirecting(): void
    {
        $middleware = new ForceHttpsMiddleware(true, new ClientIpResolver([]));
        $request    = $this->request('http', '203.0.113.9')->withMethod('POST');

        $response = $middleware->process($request, $this->failIfCalledHandler());

        self::assertSame(400, $response->getStatusCode());
    }

    public function testTrustsForwardedProtoOnlyFromATrustedProxy(): void
    {
        $middleware = new ForceHttpsMiddleware(true, new ClientIpResolver(['10.0.0.1']));

        $trusted  = $this->request('http', '10.0.0.1')->withHeader('X-Forwarded-Proto', 'https');
        $response = $middleware->process($trusted, $this->passThroughHandler());
        self::assertSame(200, $response->getStatusCode());

        $untrusted = $this->request('http', '203.0.113.9')->withHeader('X-Forwarded-Proto', 'https')->withMethod('POST');
        $response  = $middleware->process($untrusted, $this->failIfCalledHandler());
        self::assertSame(400, $response->getStatusCode());
    }

    private function request(string $scheme, string $remoteAddr): ServerRequest
    {
        return new ServerRequest(
            serverParams: ['REMOTE_ADDR' => $remoteAddr],
            uri: new \Laminas\Diactoros\Uri(($scheme === 'https' ? 'https' : 'http') . '://towerdns.example/'),
        );
    }

    private function passThroughHandler(): RequestHandlerInterface
    {
        return new readonly class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response();
            }
        };
    }

    private function failIfCalledHandler(): RequestHandlerInterface
    {
        return new readonly class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new \RuntimeException('Handler must not be reached for an insecure request.');
            }
        };
    }
}

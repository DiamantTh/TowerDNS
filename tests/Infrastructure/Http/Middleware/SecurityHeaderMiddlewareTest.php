<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Http\Middleware;

use Laminas\Diactoros\Response;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Infrastructure\Http\Middleware\SecurityHeaderMiddleware;

final class SecurityHeaderMiddlewareTest extends TestCase
{
    public function testAddsStrictHeadersAndHstsWhenExplicitlyEnabled(): void
    {
        $response = new SecurityHeaderMiddleware(true)->process(new ServerRequest(), $this->handler());

        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('strict-origin-when-cross-origin', $response->getHeaderLine('Referrer-Policy'));
        self::assertStringContainsString("frame-ancestors 'none'", $response->getHeaderLine('Content-Security-Policy'));
        self::assertStringContainsString("base-uri 'self'", $response->getHeaderLine('Content-Security-Policy'));
        self::assertStringContainsString("object-src 'none'", $response->getHeaderLine('Content-Security-Policy'));
        self::assertStringContainsString("form-action 'self'", $response->getHeaderLine('Content-Security-Policy'));
        self::assertStringContainsString("style-src 'self' 'unsafe-inline'", $response->getHeaderLine('Content-Security-Policy'));
        self::assertSame('max-age=31536000; includeSubDomains', $response->getHeaderLine('Strict-Transport-Security'));
    }

    public function testDoesNotEmitHstsForNonHttpsConfiguration(): void
    {
        $response = new SecurityHeaderMiddleware()->process(new ServerRequest(), $this->handler());

        self::assertFalse($response->hasHeader('Strict-Transport-Security'));
    }

    private function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): Response
            {
                return new Response();
            }
        };
    }
}

<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Adds security-relevant HTTP response headers on every response.
 */
final readonly class SecurityHeaderMiddleware implements MiddlewareInterface
{
    public function __construct(private bool $hstsEnabled = false) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        $response = $response
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
            ->withHeader('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                "style-src 'self' 'unsafe-inline'",
                "font-src 'self'",
                "script-src 'self'",
                "img-src 'self' data:",
                "connect-src 'self'",
                "frame-ancestors 'none'",
                "base-uri 'self'",
                "object-src 'none'",
                "form-action 'self'",
            ]));

        // HSTS is deliberately configuration-gated: enabling it on an HTTP
        // development host would make the browser cache an unusable policy.
        if ($this->hstsEnabled) {
            return $response->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}

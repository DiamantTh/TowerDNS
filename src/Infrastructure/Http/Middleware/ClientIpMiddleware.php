<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Infrastructure\Http\ClientIpResolver;

/**
 * Resolves the real client IP (honoring optionally configured trusted
 * proxies) once per request and stores it under
 * {@see ClientIpResolver::ATTRIBUTE}.
 *
 * Placed early in the pipeline so every downstream middleware and handler —
 * including Application-layer services such as AuditLogService, which must
 * not depend on Infrastructure classes — can read the resolved IP as a plain
 * PSR-7 request attribute instead of re-resolving it or reading raw
 * REMOTE_ADDR.
 */
final readonly class ClientIpMiddleware implements MiddlewareInterface
{
    public function __construct(private ClientIpResolver $resolver) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $ip = $this->resolver->resolve($request);

        return $handler->handle($request->withAttribute(ClientIpResolver::ATTRIBUTE, $ip));
    }
}

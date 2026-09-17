<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Middleware;

use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Infrastructure\Http\ClientIpResolver;

/**
 * Enforces `app.force_https` instead of leaving it half-wired.
 *
 * Previously `force_https` only toggled the session cookie's `Secure` flag
 * and the HSTS header, without ever rejecting or redirecting a plaintext
 * HTTP request — an operator enabling it got no actual enforcement.
 *
 * Scheme detection trusts `X-Forwarded-Proto` only from a REMOTE_ADDR that
 * is a configured trusted proxy (the same list used for client IP
 * resolution), so a direct client cannot spoof its way past this check.
 */
final readonly class ForceHttpsMiddleware implements MiddlewareInterface
{
    public function __construct(
        private bool $enabled,
        private ClientIpResolver $trustedProxies,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->enabled || $this->isSecure($request)) {
            return $handler->handle($request);
        }

        $method = strtoupper($request->getMethod());
        if ($method === 'GET' || $method === 'HEAD') {
            $uri = $request->getUri()->withScheme('https')->withUserInfo('');
            return new RedirectResponse((string) $uri, 301);
        }

        // Redirecting a non-idempotent request risks silently resubmitting
        // its body over plaintext HTTP first; reject it instead.
        return new HtmlResponse('HTTPS ist erforderlich.', 400);
    }

    private function isSecure(ServerRequestInterface $request): bool
    {
        if ($request->getUri()->getScheme() === 'https') {
            return true;
        }

        $server = $request->getServerParams();
        $https  = $server['HTTPS'] ?? null;
        if (is_string($https) && $https !== '' && strtolower($https) !== 'off') {
            return true;
        }

        $remoteAddr = $server['REMOTE_ADDR'] ?? null;
        if (!is_string($remoteAddr) || $remoteAddr === '' || !$this->trustedProxies->isTrustedProxy($remoteAddr)) {
            return false;
        }

        return strtolower(trim($request->getHeaderLine('X-Forwarded-Proto'))) === 'https';
    }
}

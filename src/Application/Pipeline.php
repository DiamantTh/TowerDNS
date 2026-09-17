<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application;

use Laminas\Stratigility\Middleware\ErrorHandler;
use Mezzio\Application;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Handler\NotFoundHandler;
use Mezzio\Router\Middleware\DispatchMiddleware;
use Mezzio\Router\Middleware\ImplicitHeadMiddleware;
use Mezzio\Router\Middleware\ImplicitOptionsMiddleware;
use Mezzio\Router\Middleware\RouteMiddleware;
use Mezzio\Session\SessionMiddleware;
use TowerDNS\Infrastructure\Http\Middleware\AuthenticationMiddleware;
use TowerDNS\Infrastructure\Http\Middleware\ClientIpMiddleware;
use TowerDNS\Infrastructure\Http\Middleware\ForceHttpsMiddleware;
use TowerDNS\Infrastructure\Http\Middleware\SecurityHeaderMiddleware;

final class Pipeline
{
    public static function configure(Application $app): void
    {
        // Outermost: catch all errors and render an error page
        $app->pipe(ErrorHandler::class);

        // Security headers on every response (including error pages)
        $app->pipe(SecurityHeaderMiddleware::class);

        // Resolve the real client IP (honoring configured trusted proxies)
        // once, before anything that keys on it (rate limiting, audit log).
        $app->pipe(ClientIpMiddleware::class);

        // Enforce app.force_https before session/auth so an insecure
        // request never reaches a handler with a real session cookie.
        $app->pipe(ForceHttpsMiddleware::class);

        // Session must run before authentication
        $app->pipe(SessionMiddleware::class);

        // Authenticate user from session — sets User attribute on every request
        $app->pipe(AuthenticationMiddleware::class);

        // CSRF guard — must run after session so token storage is available
        $app->pipe(CsrfMiddleware::class);

        // Route matching
        $app->pipe(RouteMiddleware::class);

        // Implicit HEAD/OPTIONS support
        $app->pipe(ImplicitHeadMiddleware::class);
        $app->pipe(ImplicitOptionsMiddleware::class);

        // Dispatch matched route handler
        $app->pipe(DispatchMiddleware::class);

        // Fallback 404
        $app->pipe(NotFoundHandler::class);
    }
}

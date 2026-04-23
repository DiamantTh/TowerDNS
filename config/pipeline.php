<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

use Laminas\Stratigility\Middleware\ErrorHandler;
use Mezzio\Application;
use Mezzio\Handler\NotFoundHandler;
use Mezzio\Helper\ServerUrlMiddleware;
use Mezzio\Helper\UrlHelperMiddleware;
use Mezzio\Router\Middleware\DispatchMiddleware;
use Mezzio\Router\Middleware\ImplicitHeadMiddleware;
use Mezzio\Router\Middleware\ImplicitOptionsMiddleware;
use Mezzio\Router\Middleware\RouteMiddleware;
use Mezzio\Session\SessionMiddleware;

return static function (Application $app): void {
    // Outermost: catch all errors and render an error page
    $app->pipe(ErrorHandler::class);

    // Session must run before authentication
    $app->pipe(SessionMiddleware::class);

    // Route matching
    $app->pipe(RouteMiddleware::class);

    // Implicit HEAD/OPTIONS support
    $app->pipe(ImplicitHeadMiddleware::class);
    $app->pipe(ImplicitOptionsMiddleware::class);

    // Dispatch matched route handler
    $app->pipe(DispatchMiddleware::class);

    // Fallback 404
    $app->pipe(NotFoundHandler::class);
};

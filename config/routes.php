<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

use Mezzio\Application;
use TowerDNS\Infrastructure\Http\Handler\LoginHandler;
use TowerDNS\Infrastructure\Http\Handler\LogoutHandler;
use TowerDNS\Infrastructure\Http\Middleware\RequireAuthMiddleware;

return static function (Application $app): void {
    // Public routes
    $app->get('/login',  LoginHandler::class,  'login.form');
    $app->post('/login', LoginHandler::class,  'login.submit');
    $app->post('/logout', [RequireAuthMiddleware::class, LogoutHandler::class], 'logout');

    // Dashboard (protected)
    $app->get('/', [RequireAuthMiddleware::class, \TowerDNS\Infrastructure\Http\Handler\DashboardHandler::class], 'dashboard');
};

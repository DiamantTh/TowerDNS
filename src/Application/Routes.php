<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application;

use Mezzio\Application;
use TowerDNS\Infrastructure\Http\Handler\DashboardHandler;
use TowerDNS\Infrastructure\Http\Handler\LoginHandler;
use TowerDNS\Infrastructure\Http\Handler\LogoutHandler;
use TowerDNS\Infrastructure\Http\Handler\RecordCreateHandler;
use TowerDNS\Infrastructure\Http\Handler\RecordDeleteHandler;
use TowerDNS\Infrastructure\Http\Handler\RecordListHandler;
use TowerDNS\Infrastructure\Http\Handler\ZoneCreateHandler;
use TowerDNS\Infrastructure\Http\Handler\ZoneDeleteHandler;
use TowerDNS\Infrastructure\Http\Handler\ZoneListHandler;
use TowerDNS\Infrastructure\Http\Middleware\RequireAuthMiddleware;

final class Routes
{
    public static function configure(Application $app): void
    {
        // ── Public ────────────────────────────────────────────────────────────
        $app->get('/login',  LoginHandler::class, 'login.form');
        $app->post('/login', LoginHandler::class, 'login.submit');
        $app->post('/logout', [RequireAuthMiddleware::class, LogoutHandler::class], 'logout');

        // ── Dashboard ─────────────────────────────────────────────────────────
        $app->get('/', [RequireAuthMiddleware::class, DashboardHandler::class], 'dashboard');

        // ── DNS zones ─────────────────────────────────────────────────────────
        $app->get('/zones', [RequireAuthMiddleware::class, ZoneListHandler::class], 'zones.list');
        $app->post('/zones/{provider}', [RequireAuthMiddleware::class, ZoneCreateHandler::class], 'zones.create');
        $app->post('/zones/{provider}/{zone}/delete', [RequireAuthMiddleware::class, ZoneDeleteHandler::class], 'zones.delete');

        // ── DNS records ───────────────────────────────────────────────────────
        $app->get('/zones/{provider}/{zone}', [RequireAuthMiddleware::class, RecordListHandler::class], 'records.list');
        $app->post('/zones/{provider}/{zone}/records', [RequireAuthMiddleware::class, RecordCreateHandler::class], 'records.create');
        $app->post('/zones/{provider}/{zone}/records/{record}/delete', [RequireAuthMiddleware::class, RecordDeleteHandler::class], 'records.delete');
    }
}

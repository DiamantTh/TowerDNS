<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application;

use Mezzio\Application;
use TowerDNS\Infrastructure\Http\Handler\DashboardHandler;
use TowerDNS\Infrastructure\Http\Handler\DnssecHandler;
use TowerDNS\Infrastructure\Http\Handler\SystemSettingsHandler;
use TowerDNS\Infrastructure\Http\Handler\LoginHandler;
use TowerDNS\Infrastructure\Http\Handler\LogoutHandler;
use TowerDNS\Infrastructure\Http\Handler\PasswordChangeHandler;
use TowerDNS\Infrastructure\Http\Handler\ProfileHandler;
use TowerDNS\Infrastructure\Http\Handler\RecordCreateHandler;
use TowerDNS\Infrastructure\Http\Handler\RecordDeleteHandler;
use TowerDNS\Infrastructure\Http\Handler\RecordEditHandler;
use TowerDNS\Infrastructure\Http\Handler\RecordListHandler;
use TowerDNS\Infrastructure\Http\Handler\RoleCreateHandler;
use TowerDNS\Infrastructure\Http\Handler\RoleDeleteHandler;
use TowerDNS\Infrastructure\Http\Handler\RoleEditHandler;
use TowerDNS\Infrastructure\Http\Handler\RoleListHandler;
use TowerDNS\Infrastructure\Http\Handler\TotpHandler;
use TowerDNS\Infrastructure\Http\Handler\TotpSetupHandler;
use TowerDNS\Infrastructure\Http\Handler\UserCreateHandler;
use TowerDNS\Infrastructure\Http\Handler\UserDeleteHandler;
use TowerDNS\Infrastructure\Http\Handler\UserEditHandler;
use TowerDNS\Infrastructure\Http\Handler\UserListHandler;
use TowerDNS\Infrastructure\Http\Handler\WebAuthnAuthFinishHandler;
use TowerDNS\Infrastructure\Http\Handler\WebAuthnAuthHandler;
use TowerDNS\Infrastructure\Http\Handler\WebAuthnDeleteHandler;
use TowerDNS\Infrastructure\Http\Handler\WebAuthnProfileHandler;
use TowerDNS\Infrastructure\Http\Handler\WebAuthnRegisterBeginHandler;
use TowerDNS\Infrastructure\Http\Handler\WebAuthnRegisterFinishHandler;
use TowerDNS\Infrastructure\Http\Handler\ZoneCreateHandler;
use TowerDNS\Infrastructure\Http\Handler\ZoneDeleteHandler;
use TowerDNS\Infrastructure\Http\Handler\ZoneListHandler;
use TowerDNS\Infrastructure\Http\Handler\ZoneUpdateHandler;
use TowerDNS\Infrastructure\Http\Middleware\RequireAuthMiddleware;

final class Routes
{
    public static function configure(Application $app): void
    {
        // ── Public ────────────────────────────────────────────────────────────
        $app->get('/login',  LoginHandler::class, 'login.form');
        $app->post('/login', LoginHandler::class, 'login.submit');
        $app->get('/login/totp',  TotpHandler::class, 'login.totp.form');
        $app->post('/login/totp', TotpHandler::class, 'login.totp.submit');        $app->get('/login/webauthn',        WebAuthnAuthHandler::class,       'login.webauthn.form');
        $app->post('/login/webauthn/finish', WebAuthnAuthFinishHandler::class, 'login.webauthn.finish');        $app->post('/logout', [RequireAuthMiddleware::class, LogoutHandler::class], 'logout');

        // ── Dashboard ─────────────────────────────────────────────────────────
        $app->get('/', [RequireAuthMiddleware::class, DashboardHandler::class], 'dashboard');

        // ── Profile ───────────────────────────────────────────────────────────
        $app->get('/profile',          [RequireAuthMiddleware::class, ProfileHandler::class],       'profile');
        $app->post('/profile',         [RequireAuthMiddleware::class, ProfileHandler::class],       'profile.update');
        $app->get('/profile/totp',     [RequireAuthMiddleware::class, TotpSetupHandler::class],    'profile.totp.form');
        $app->post('/profile/totp',    [RequireAuthMiddleware::class, TotpSetupHandler::class],    'profile.totp.submit');
        $app->get('/profile/password', [RequireAuthMiddleware::class, PasswordChangeHandler::class], 'profile.password.form');
        $app->post('/profile/password',[RequireAuthMiddleware::class, PasswordChangeHandler::class], 'profile.password.submit');
        $app->get('/profile/webauthn',                              [RequireAuthMiddleware::class, WebAuthnProfileHandler::class],         'profile.webauthn');
        $app->post('/profile/webauthn/register/begin',              [RequireAuthMiddleware::class, WebAuthnRegisterBeginHandler::class],   'profile.webauthn.register.begin');
        $app->post('/profile/webauthn/register/finish',             [RequireAuthMiddleware::class, WebAuthnRegisterFinishHandler::class],  'profile.webauthn.register.finish');
        $app->post('/profile/webauthn/{credentialId}/delete',       [RequireAuthMiddleware::class, WebAuthnDeleteHandler::class],          'profile.webauthn.delete');

        // ── IAM ───────────────────────────────────────────────────────────────
        $app->get('/users',                       [RequireAuthMiddleware::class, UserListHandler::class],   'users.list');
        $app->post('/users',                      [RequireAuthMiddleware::class, UserCreateHandler::class], 'users.create');
        $app->get('/users/{id}',                  [RequireAuthMiddleware::class, UserEditHandler::class],   'users.edit.form');
        $app->post('/users/{id}',                 [RequireAuthMiddleware::class, UserEditHandler::class],   'users.edit.submit');
        $app->post('/users/{id}/delete',          [RequireAuthMiddleware::class, UserDeleteHandler::class], 'users.delete');
        $app->get('/roles',                       [RequireAuthMiddleware::class, RoleListHandler::class],   'roles.list');
        $app->post('/roles',                      [RequireAuthMiddleware::class, RoleCreateHandler::class], 'roles.create');
        $app->get('/roles/{id}',                  [RequireAuthMiddleware::class, RoleEditHandler::class],   'roles.edit.form');
        $app->post('/roles/{id}',                 [RequireAuthMiddleware::class, RoleEditHandler::class],   'roles.edit.submit');
        $app->post('/roles/{id}/delete',          [RequireAuthMiddleware::class, RoleDeleteHandler::class], 'roles.delete');
        // ── DNS zones ─────────────────────────────────────────────────────────
        $app->get('/zones', [RequireAuthMiddleware::class, ZoneListHandler::class], 'zones.list');
        $app->post('/zones/{provider}', [RequireAuthMiddleware::class, ZoneCreateHandler::class], 'zones.create');
        $app->post('/zones/{provider}/{zone}/delete', [RequireAuthMiddleware::class, ZoneDeleteHandler::class], 'zones.delete');

        // ── DNS records ───────────────────────────────────────────────────────
        $app->get('/zones/{provider}/{zone}', [RequireAuthMiddleware::class, RecordListHandler::class], 'records.list');
        $app->post('/zones/{provider}/{zone}/records', [RequireAuthMiddleware::class, RecordCreateHandler::class], 'records.create');
        $app->get('/zones/{provider}/{zone}/records/{record}/edit', [RequireAuthMiddleware::class, RecordEditHandler::class], 'records.edit.form');
        $app->post('/zones/{provider}/{zone}/records/{record}/update', [RequireAuthMiddleware::class, ZoneUpdateHandler::class], 'records.update');
        $app->post('/zones/{provider}/{zone}/records/{record}/delete', [RequireAuthMiddleware::class, RecordDeleteHandler::class], 'records.delete');

        // ── DNSSEC ────────────────────────────────────────────────────────────
        $app->get('/zones/{provider}/{zone}/dnssec',  [RequireAuthMiddleware::class, DnssecHandler::class], 'dnssec.status');
        $app->post('/zones/{provider}/{zone}/dnssec', [RequireAuthMiddleware::class, DnssecHandler::class], 'dnssec.action');

        // ── System settings ───────────────────────────────────────────────
        $app->get('/settings',  [RequireAuthMiddleware::class, SystemSettingsHandler::class], 'settings.form');
        $app->post('/settings', [RequireAuthMiddleware::class, SystemSettingsHandler::class], 'settings.submit');
    }
}

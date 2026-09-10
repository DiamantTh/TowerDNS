#!/usr/bin/env php
<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * TowerDNS — CLI-Installer
 *
 * Interaktive Installation ohne Web-Browser.
 * Benötigt: PHP 8.4+, Composer vendor/, pdo, pdo_mysql|pdo_pgsql|pdo_sqlite,
 *           openssl, sodium, intl
 *
 * Verwendung: php bin/towerdns
 *
 * This compatibility wrapper may be removed in a future major release.
 */

require dirname(__DIR__) . '/bin/towerdns';

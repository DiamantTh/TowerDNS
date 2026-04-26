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
 * Verwendung: php install/install-cli.php
 */

// ── PHP-Versionscheck ─────────────────────────────────────────────────────
if (PHP_MAJOR_VERSION < 8 || (PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION < 4)) {
    fwrite(STDERR, "TowerDNS requires PHP >= 8.4 (you have " . PHP_VERSION . ")\n");
    exit(1);
}

// ── CLI-Kontext ───────────────────────────────────────────────────────────
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

// ── Autoloader ───────────────────────────────────────────────────────────
$projectRoot = dirname(__DIR__);
$autoload    = $projectRoot . '/vendor/autoload.php';

if (!file_exists($autoload)) {
    fwrite(STDERR, "vendor/autoload.php not found.\nRun: composer install --no-dev\n");
    exit(1);
}

require_once $autoload;

// ── Symfony Console Application ───────────────────────────────────────────
use Symfony\Component\Console\Application;
use TowerDNS\Infrastructure\Console\InstallCommand;
use TowerDNS\Infrastructure\Console\PasswordResetCommand;

$app = new Application('TowerDNS CLI', '1.0.0');
$app->add(new InstallCommand($projectRoot));
$app->add(new PasswordResetCommand($projectRoot));
$app->setDefaultCommand('towerdns:install');
$app->run();


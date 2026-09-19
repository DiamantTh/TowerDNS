<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * TowerDNS Installer — Bootstrap
 *
 * Definiert Konstanten, startet die Session, validiert den CSRF-Token
 * und prüft den optionalen Installer-Token-Schutz.
 */

define('PROJECT_ROOT', dirname(__DIR__, 2));
define('INSTALL_DIR', dirname(__DIR__));
define('LOCK_FILE', INSTALL_DIR . '/.lock');
define('INSTALLATION_MARKER', PROJECT_ROOT . '/configs/.installed');
define('TOKEN_FILE', INSTALL_DIR . '/.install_token');
define('INSTALLER_ENTRY', basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'install.php')));
define('VENDOR_OK', is_dir(PROJECT_ROOT . '/vendor') && is_file(PROJECT_ROOT . '/vendor/autoload.php'));

require_once PROJECT_ROOT . '/src/Infrastructure/Installation/InstallationState.php';

// ── Session ────────────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_name('towerdns_installer');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off' && $_SERVER['HTTPS'] !== '',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ── CSRF ───────────────────────────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
define('CSRF_TOKEN', $_SESSION['csrf_token']);

function verifyCsrf(): void
{
    $posted = $_POST['csrf_token'] ?? '';
    if (!hash_equals(CSRF_TOKEN, $posted)) {
        http_response_code(400);
        exit('Invalid request.');
    }
}

// ── Installer-Token-Schutz ─────────────────────────────────────────────────
function checkInstallerToken(): bool
{
    if (!is_file(TOKEN_FILE)) {
        $token = bin2hex(random_bytes(24));
        if (file_put_contents(TOKEN_FILE, $token, LOCK_EX) === false || !chmod(TOKEN_FILE, 0o600)) {
            return false;
        }
        // The first browser that provisions the token owns this installer
        // session. A later session still needs the filesystem token.
        $_SESSION['installer_authenticated'] = true;
        return true;
    }

    $stored      = file_get_contents(TOKEN_FILE);
    $storedToken = is_string($stored) ? rtrim($stored) : '';
    if ($storedToken === '') {
        return false;
    }

    if (!empty($_SESSION['installer_authenticated'])) {
        return true;
    }

    if (isset($_POST['install_token'])) {
        if (hash_equals($storedToken, $_POST['install_token'])) {
            $_SESSION['installer_authenticated'] = true;
            return true;
        }
        return false;
    }

    return false;
}

function installationIsLocked(): bool
{
    return TowerDNS\Infrastructure\Installation\InstallationState::isLocked(PROJECT_ROOT);
}

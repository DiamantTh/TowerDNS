<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

/**
 * Web-Installer-Einstiegspunkt.
 *
 * Leitet die Anfrage an install/index.php weiter.
 * Der Installer prüft selbst, ob er bereits gesperrt ist (Marker außerhalb
 * von install/ oder der Legacy-Lockdatei).
 */
header('Cache-Control: no-store');
$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/src/Infrastructure/Ui/AssetManifestException.php';
require_once $projectRoot . '/src/Infrastructure/Ui/AssetManifest.php';
try {
    $GLOBALS['_installer_assets'] = (new \TowerDNS\Infrastructure\Ui\AssetManifest($projectRoot))->resolve();
} catch (\RuntimeException $exception) {
    error_log($exception->getMessage());
    http_response_code(503);
    echo 'TowerDNS frontend assets are unavailable.';
    exit;
}

$installer   = $projectRoot . '/install/index.php';

if (!is_file($installer)) {
    require_once $projectRoot . '/src/Infrastructure/Installation/InstallationState.php';

    if (\TowerDNS\Infrastructure\Installation\InstallationState::isLocked($projectRoot)) {
        header('Location: /', true, 302);
        exit;
    }

    http_response_code(503);
    echo 'TowerDNS installer is unavailable.';
    exit;
}

require $installer;

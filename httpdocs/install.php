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
require_once $projectRoot . '/src/Infrastructure/Installation/DocumentRootPolicy.php';
if (!\TowerDNS\Infrastructure\Installation\DocumentRootPolicy::matches(
    $projectRoot,
    isset($_SERVER['DOCUMENT_ROOT']) ? (string) $_SERVER['DOCUMENT_ROOT'] : null,
)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'TowerDNS requires the web server DocumentRoot to be the httpdocs directory.';
    exit;
}
require_once $projectRoot . '/src/Infrastructure/Ui/AssetManifestException.php';
require_once $projectRoot . '/src/Infrastructure/Ui/AssetManifest.php';
try {
    $assetManifest = new \TowerDNS\Infrastructure\Ui\AssetManifest($projectRoot);
    $assetStatus = $assetManifest->status();
    if ($assetStatus === 'broken') {
        throw new \TowerDNS\Infrastructure\Ui\AssetManifestException('Active frontend release failed the installer check.');
    }
    $GLOBALS['_installer_assets'] = $assetManifest->resolve();
    $GLOBALS['_installer_assets_status'] = $assetStatus;
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

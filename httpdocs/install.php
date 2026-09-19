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
$projectRoot = dirname(__DIR__);
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

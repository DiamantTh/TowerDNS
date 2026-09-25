<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

define('PROJECT_ROOT', dirname(__DIR__));

require_once PROJECT_ROOT . '/src/Infrastructure/Installation/InstallationState.php';

// The marker lives outside the public document root and survives removal of
// the installer directory after a successful setup. Keep the legacy lock as
// a compatibility fallback for older installations.
if (!\TowerDNS\Infrastructure\Installation\InstallationState::isLocked(PROJECT_ROOT)) {
    header('Location: /install.php', true, 302);
    exit;
}

$autoload = PROJECT_ROOT . '/vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(503);
    echo 'TowerDNS application dependencies are unavailable.';
    exit;
}

require $autoload;

$container = \TowerDNS\Application\ContainerFactory::create(PROJECT_ROOT);

/** @var \Mezzio\Application $app */
$app = $container->get(\Mezzio\Application::class);

\TowerDNS\Application\Pipeline::configure($app);
\TowerDNS\Application\Routes::configure($app);

$app->run();

<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

define('PROJECT_ROOT', dirname(__DIR__));

// The marker lives outside the public document root and survives removal of
// the installer directory after a successful setup. Keep the legacy lock as
// a compatibility fallback for older installations.
if (!is_file(PROJECT_ROOT . '/configs/.installed')
    && !is_file(PROJECT_ROOT . '/install/.lock')
    && !(!is_dir(PROJECT_ROOT . '/install')
        && is_file(PROJECT_ROOT . '/configs/config.local.toml')
        && is_file(PROJECT_ROOT . '/configs/database.toml')
        && is_file(PROJECT_ROOT . '/configs/providers.toml'))
) {
    header('Location: /install.php', true, 302);
    exit;
}

require PROJECT_ROOT . '/vendor/autoload.php';

$container = \TowerDNS\Application\ContainerFactory::create(PROJECT_ROOT);

/** @var \Mezzio\Application $app */
$app = $container->get(\Mezzio\Application::class);

\TowerDNS\Application\Pipeline::configure($app);
\TowerDNS\Application\Routes::configure($app);

$app->run();

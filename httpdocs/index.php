<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

define('PROJECT_ROOT', dirname(__DIR__));

// Zum Installer weiterleiten, wenn noch nicht installiert
if (!file_exists(PROJECT_ROOT . '/install/.lock')) {
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

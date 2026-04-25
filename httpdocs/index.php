<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

define('PROJECT_ROOT', dirname(__DIR__));

require PROJECT_ROOT . '/vendor/autoload.php';

/** @var \DI\Container $container */
$container = require PROJECT_ROOT . '/configs/container.php';

/** @var \Mezzio\Application $app */
$app = $container->get(\Mezzio\Application::class);

(require PROJECT_ROOT . '/config/pipeline.php')($app);
(require PROJECT_ROOT . '/config/routes.php')($app);

$app->run();

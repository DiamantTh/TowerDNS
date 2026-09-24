<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http;

use Mezzio\Application;
use TowerDNS\Application\ContainerFactory;
use TowerDNS\Application\Pipeline;
use TowerDNS\Application\Routes;
use TowerDNS\Infrastructure\Installation\InstallationState;

/** Shared entry for every public application PHP file (not the installer). */
final class HttpBootstrap
{
    public static function run(string $projectRoot): void
    {
        if (!InstallationState::isLocked($projectRoot)) {
            header('Location: /install.php', true, 302);
            return;
        }

        $autoload = $projectRoot . '/vendor/autoload.php';
        if (!is_file($autoload)) {
            http_response_code(503);
            echo 'TowerDNS application dependencies are unavailable.';
            return;
        }

        require_once $autoload;

        $container = ContainerFactory::create($projectRoot);
        /** @var Application $app */
        $app = $container->get(Application::class);
        Pipeline::configure($app);
        Routes::configure($app);
        $app->run();
    }
}

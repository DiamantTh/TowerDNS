<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

$projectRoot = dirname(__DIR__, 3);
require_once $projectRoot . '/src/Infrastructure/Installation/InstallationState.php';
require_once __DIR__ . '/HttpBootstrap.php';

TowerDNS\Infrastructure\Http\HttpBootstrap::run($projectRoot);

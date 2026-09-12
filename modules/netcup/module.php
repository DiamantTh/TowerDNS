<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

use TowerDNS\Module\netcup\NetcupModule;

require_once __DIR__ . '/src/NetcupAPIException.php';
require_once __DIR__ . '/src/NetcupAPIClient.php';
require_once __DIR__ . '/src/NetcupProvider.php';
require_once __DIR__ . '/src/NetcupModule.php';

return new NetcupModule();

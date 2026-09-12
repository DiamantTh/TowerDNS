<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

use TowerDNS\Module\INWX\INWXModule;

require_once __DIR__ . '/src/INWXAPIException.php';
require_once __DIR__ . '/src/INWXAPIClient.php';
require_once __DIR__ . '/src/INWXProvider.php';
require_once __DIR__ . '/src/INWXModule.php';

return new INWXModule();

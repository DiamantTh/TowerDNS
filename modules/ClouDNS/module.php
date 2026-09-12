<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

use TowerDNS\Module\ClouDNS\ClouDNSModule;

require_once __DIR__ . '/src/ClouDNSAPIClient.php';
require_once __DIR__ . '/src/ClouDNSProvider.php';
require_once __DIR__ . '/src/ClouDNSModule.php';

return new ClouDNSModule();

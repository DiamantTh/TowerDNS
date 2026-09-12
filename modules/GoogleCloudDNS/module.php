<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

use TowerDNS\Module\GoogleCloudDNS\GoogleCloudDNSModule;

require_once __DIR__ . '/src/GoogleCloudDNSProvider.php';
require_once __DIR__ . '/src/GoogleCloudDNSModule.php';

return new GoogleCloudDNSModule();

<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

use TowerDNS\Module\OVHcloud\OVHcloudModule;

require_once __DIR__ . '/src/OVHcloudProvider.php';
require_once __DIR__ . '/src/OVHcloudModule.php';

return new OVHcloudModule();

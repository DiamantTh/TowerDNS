<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

require_once __DIR__ . '/src/PowerDNSAPIClient.php';
require_once __DIR__ . '/src/PowerDNSProvider.php';
require_once __DIR__ . '/src/PowerDNSModule.php';

return new TowerDNS\Module\PowerDNS\PowerDNSModule();

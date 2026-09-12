<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

require_once __DIR__ . '/src/DeSECApiException.php';
require_once __DIR__ . '/src/DeSECApiClient.php';
require_once __DIR__ . '/src/DeSECProvider.php';
require_once __DIR__ . '/src/DeSECModule.php';

return new TowerDNS\Module\DeSEC\DeSECModule();

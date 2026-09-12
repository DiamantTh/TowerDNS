<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

require_once __DIR__ . '/src/CloudflareAPIException.php';
require_once __DIR__ . '/src/CloudflareAPIClient.php';
require_once __DIR__ . '/src/CloudflareProvider.php';
require_once __DIR__ . '/src/CloudflareModule.php';

return new TowerDNS\Module\Cloudflare\CloudflareModule();

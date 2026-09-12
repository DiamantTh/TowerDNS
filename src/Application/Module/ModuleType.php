<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Module;

/** Categories share one module system while keeping their contracts explicit. */
enum ModuleType: string
{
    case PROVIDER    = 'provider';
    case FEATURE     = 'feature';
    case INTEGRATION = 'integration';
}

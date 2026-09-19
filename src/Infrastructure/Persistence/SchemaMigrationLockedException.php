<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Persistence;

final class SchemaMigrationLockedException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Another TowerDNS schema upgrade is already running.');
    }
}

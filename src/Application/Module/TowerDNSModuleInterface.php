<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Module;

/** Runtime entry point returned by a local modules/<Name>/module.php file. */
interface TowerDNSModuleInterface
{
    public function manifest(): ModuleManifest;
}

<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Module;

use TowerDNS\Application\Auth\ActionGroupRegistry;
use TowerDNS\Domain\Auth\PermissionRegistry;

/** Builds the central action-group catalogue from Core and active modules. */
final readonly class ModuleActionGroupRegistryFactory
{
    public function __construct(
        private LocalModuleDiscovery $discovery,
        private PermissionRegistry $permissions,
    ) {}

    public function create(): ActionGroupRegistry
    {
        $registry = new ActionGroupRegistry($this->permissions);

        foreach ($this->discovery->actionGroupDefinitions() as $definition) {
            $registry->register($definition);
        }

        return $registry;
    }
}

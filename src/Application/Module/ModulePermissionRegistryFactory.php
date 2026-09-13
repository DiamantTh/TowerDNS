<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Module;

use TowerDNS\Domain\Auth\PermissionRegistry;

/** Builds the central permission catalogue from Core and active modules. */
final readonly class ModulePermissionRegistryFactory
{
    public function __construct(private LocalModuleDiscovery $discovery) {}

    public function create(): PermissionRegistry
    {
        $registry = new PermissionRegistry();

        foreach ($this->discovery->permissionDefinitions() as $definition) {
            $registry->register($definition);
        }

        return $registry;
    }
}

<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Module;

use TowerDNS\Application\Exception\ProviderNotFoundException;

/** Provider catalogue built exclusively from generic provider-module contributions. */
final class ProviderModuleRegistry
{
    /** @var array<string, ProviderModuleInterface> */
    private array $modules = [];

    /** @param iterable<ProviderModuleInterface> $modules */
    public function __construct(iterable $modules = [])
    {
        foreach ($modules as $module) {
            $this->register($module);
        }
    }

    public function register(ProviderModuleInterface $module): void
    {
        $id = $module->providerDefinition()->id;
        if (isset($this->modules[$id])) {
            throw new \LogicException(sprintf('Provider-Modul für "%s" ist bereits registriert.', $id));
        }
        $this->modules[$id] = $module;
    }

    public function get(string $id): ProviderModuleInterface
    {
        return $this->modules[$id] ?? throw ProviderNotFoundException::forId($id);
    }

    public function has(string $id): bool
    {
        return isset($this->modules[$id]);
    }

    /** @return array<string, ProviderDefinition> */
    public function definitions(): array
    {
        return array_map(static fn(ProviderModuleInterface $module): ProviderDefinition => $module->providerDefinition(), $this->modules);
    }
}

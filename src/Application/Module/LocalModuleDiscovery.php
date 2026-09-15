<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Module;

/**
 * Finds modules directly below modules/<ModuleName>/module.php.
 *
 * Folder names deliberately remain presentation names (for example deSEC,
 * PowerDNS, INWX, OVHcloud or TLSA). They never derive technical IDs. Composer
 * repositories and package types are irrelevant here.
 */
final readonly class LocalModuleDiscovery
{
    /** @param null|list<string> $enabledModuleIds Null enables every locally discovered module. */
    public function __construct(
        private string $moduleDirectory,
        private string $towerDnsVersion = '1.0.0',
        private ?array $enabledModuleIds = null,
    ) {}

    /** @return list<ModuleManifest> */
    public function discover(): array
    {
        return array_map(
            static fn(ModuleManifest|TowerDNSModuleInterface $module): ModuleManifest => $module instanceof ModuleManifest ? $module : $module->manifest(),
            $this->load(),
        );
    }

    /** @return list<ProviderModuleInterface> */
    public function providerModules(): array
    {
        return array_values(array_filter(
            $this->load(),
            static fn(ModuleManifest|TowerDNSModuleInterface $module): bool => $module instanceof ProviderModuleInterface,
        ));
    }

    /** @return list<\TowerDNS\Domain\Auth\PermissionDefinition> */
    public function permissionDefinitions(): array
    {
        $definitions = [];

        foreach ($this->load() as $module) {
            if (!$module instanceof PermissionContributorInterface) {
                continue;
            }
            foreach ($module->permissionDefinitions() as $definition) {
                $definitions[] = $definition;
            }
        }

        return $definitions;
    }

    /** @return list<\TowerDNS\Application\Auth\ActionGroupDefinition> */
    public function actionGroupDefinitions(): array
    {
        $definitions = [];

        foreach ($this->load() as $module) {
            if (!$module instanceof ActionGroupContributorInterface) {
                continue;
            }
            foreach ($module->actionGroupDefinitions() as $definition) {
                $definitions[] = $definition;
            }
        }

        return $definitions;
    }

    /** @return list<ModuleManifest|TowerDNSModuleInterface> */
    private function load(): array
    {
        if (!is_dir($this->moduleDirectory)) {
            return [];
        }
        $files = glob($this->moduleDirectory . '/*/module.php') ?: [];
        sort($files, SORT_STRING);
        $modules     = [];
        $directories = [];
        foreach ($files as $file) {
            $directory    = basename(dirname($file));
            $directoryKey = strtolower($directory);
            if (isset($directories[$directoryKey])) {
                throw new \RuntimeException(sprintf(
                    'Case-colliding module directories: %s and %s.',
                    $directories[$directoryKey],
                    $directory,
                ));
            }
            $directories[$directoryKey] = $directory;

            $module = require $file;
            if (!$module instanceof ModuleManifest && !$module instanceof TowerDNSModuleInterface) {
                throw new \RuntimeException(sprintf('Module entry point %s must return a TowerDNS module or ModuleManifest.', $file));
            }
            $manifest = $module instanceof ModuleManifest ? $module : $module->manifest();
            $key      = strtolower($manifest->id);
            if (isset($modules[$key])) {
                throw new \RuntimeException(sprintf('Case-colliding module ID: %s', $manifest->id));
            }
            if (!version_compare($this->towerDnsVersion, ltrim($manifest->requiresTowerDns, '>='), '>=')) {
                throw new \RuntimeException(sprintf('Module %s requires TowerDNS %s.', $manifest->id, $manifest->requiresTowerDns));
            }
            $modules[$key] = $module;
        }
        $enabled = $this->enabledModuleIds === null
            ? $modules
            : array_filter($modules, $this->isEnabled(...));

        foreach ($enabled as $module) {
            $manifest = $module instanceof ModuleManifest ? $module : $module->manifest();
            foreach ($manifest->dependencies as $dependency) {
                if (!isset($modules[$dependency])) {
                    throw new \RuntimeException(sprintf('Active module %s requires missing module %s.', $manifest->id, $dependency));
                }
                if (!isset($enabled[$dependency])) {
                    throw new \RuntimeException(sprintf('Active module %s requires disabled module %s.', $manifest->id, $dependency));
                }
            }
        }

        return $this->sortByDependencies($enabled);
    }

    private function isEnabled(ModuleManifest|TowerDNSModuleInterface $module): bool
    {
        $manifest = $module instanceof ModuleManifest ? $module : $module->manifest();

        return array_any($this->enabledModuleIds ?? [], static function (mixed $id) use ($manifest): bool {
            if (!is_string($id)) {
                throw new \InvalidArgumentException('Enabled module IDs must be strings.');
            }

            return $id === $manifest->id;
        });
    }

    /**
     * Deterministic lexical topological ordering. Dependencies always precede
     * their dependants; unrelated modules are ordered by technical ID.
     *
     * @param array<string, ModuleManifest|TowerDNSModuleInterface> $modules
     * @return list<ModuleManifest|TowerDNSModuleInterface>
     */
    private function sortByDependencies(array $modules): array
    {
        ksort($modules, SORT_STRING);
        /** @var array<string, 'visiting'|'visited'> $states */
        $states = [];
        /** @var list<ModuleManifest|TowerDNSModuleInterface> $ordered */
        $ordered = [];
        /** @var list<string> $path */
        $path = [];

        $visit = function (string $id) use (&$visit, &$states, &$ordered, &$path, $modules): void {
            if (($states[$id] ?? null) === 'visited') {
                return;
            }
            if (($states[$id] ?? null) === 'visiting') {
                $cycleStart = array_search($id, $path, true);
                $cycle      = [...array_slice($path, $cycleStart === false ? 0 : $cycleStart), $id];
                throw new \RuntimeException('Module dependency cycle: ' . implode(' -> ', $cycle));
            }

            $states[$id]  = 'visiting';
            $path[]       = $id;
            $manifest     = $modules[$id] instanceof ModuleManifest ? $modules[$id] : $modules[$id]->manifest();
            $dependencies = $manifest->dependencies;
            sort($dependencies, SORT_STRING);
            foreach ($dependencies as $dependency) {
                $visit($dependency);
            }
            array_pop($path);
            $states[$id] = 'visited';
            $ordered[]   = $modules[$id];
        };

        foreach (array_keys($modules) as $id) {
            $visit($id);
        }

        return $ordered;
    }
}

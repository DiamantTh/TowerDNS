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
    public function __construct(private string $moduleDirectory, private string $towerDnsVersion = '1.0.0') {}

    /** @return list<ModuleManifest> */
    public function discover(): array
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
                    'Case-kollidierende Modulordner: %s und %s.',
                    $directories[$directoryKey],
                    $directory,
                ));
            }
            $directories[$directoryKey] = $directory;

            $manifest = require $file;
            if (!$manifest instanceof ModuleManifest) {
                throw new \RuntimeException(sprintf('Modulmanifest %s muss ModuleManifest zurückgeben.', $file));
            }
            $key = strtolower($manifest->id);
            if (isset($modules[$key])) {
                throw new \RuntimeException(sprintf('Case-kollidierende Modul-ID: %s', $manifest->id));
            }
            if (!version_compare($this->towerDnsVersion, ltrim($manifest->requiresTowerDns, '>='), '>=')) {
                throw new \RuntimeException(sprintf('Modul %s benötigt TowerDNS %s.', $manifest->id, $manifest->requiresTowerDns));
            }
            $modules[$key] = $manifest;
        }
        foreach ($modules as $manifest) {
            foreach ($manifest->dependencies as $dependency) {
                if (!isset($modules[$dependency])) {
                    throw new \RuntimeException(sprintf('Modul %s benötigt das fehlende Modul %s.', $manifest->id, $dependency));
                }
            }
        }
        return array_values($modules);
    }
}

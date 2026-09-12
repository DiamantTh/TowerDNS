<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Module;

/** Immutable metadata for a locally discovered TowerDNS module. */
final readonly class ModuleManifest
{
    /** @param list<string> $dependencies */
    public function __construct(
        public string $id,
        public string $displayName,
        public string $version,
        public ModuleType $type,
        public string $requiresTowerDns = '>=1.0.0',
        public array $dependencies = [],
    ) {
        if (preg_match('/^[a-z][a-z0-9]*(?:\.[a-z][a-z0-9-]*)+$/D', $id) !== 1) {
            throw new \InvalidArgumentException('Modul-ID muss klein geschrieben und punkt-namespaced sein.');
        }
        if ($displayName === '' || !preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/D', $version)) {
            throw new \InvalidArgumentException('Modulname oder Modulversion ist ungültig.');
        }
        foreach ($dependencies as $dependency) {
            if (!is_string($dependency) || preg_match('/^[a-z][a-z0-9]*(?:\.[a-z][a-z0-9-]*)+$/D', $dependency) !== 1) {
                throw new \InvalidArgumentException('Modulabhängigkeiten müssen normalisierte Modul-IDs sein.');
            }
        }
    }
}

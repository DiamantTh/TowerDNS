<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Persistence;

use Devium\Toml\Toml;
use TowerDNS\Application\Repository\SystemProviderConfigurationStoreInterface;

/** TOML-backed storage for intentionally system-wide provider instances. */
final readonly class TomlSystemProviderConfigurationStore implements SystemProviderConfigurationStoreInterface
{
    public function __construct(private string $path) {}

    public function load(): array
    {
        if (!is_file($this->path)) {
            return ['providers' => []];
        }

        $raw = file_get_contents($this->path);
        if ($raw === false || $raw === '') {
            return ['providers' => []];
        }

        /** @var array<string, mixed> $data */
        $data = (array) Toml::decode($raw, asArray: true);
        return $data;
    }

    public function save(array $configuration): void
    {
        $directory = dirname($this->path);
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new \RuntimeException('System provider configuration directory is not writable.');
        }

        $content = "# TowerDNS system provider configuration\n";
        $content .= "# This file may contain secrets. Do not commit it.\n\n";
        $content .= Toml::encode($configuration);

        $temporary = tempnam($directory, '.providers-');
        if ($temporary === false) {
            throw new \RuntimeException('Unable to create a temporary system provider configuration file.');
        }

        try {
            if (file_put_contents($temporary, $content, LOCK_EX) === false || !chmod($temporary, 0o600)) {
                throw new \RuntimeException('Unable to write system provider configuration.');
            }
            if (!rename($temporary, $this->path)) {
                throw new \RuntimeException('Unable to replace system provider configuration.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    public function update(callable $mutator): void
    {
        $lockPath = $this->path . '.lock';
        $lock     = fopen($lockPath, 'c');
        if ($lock === false) {
            throw new \RuntimeException('Unable to lock system provider configuration.');
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException('Unable to acquire system provider configuration lock.');
            }
            $this->save($mutator($this->load()));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

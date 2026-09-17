<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Services;

use Devium\Toml\Toml;
use Doctrine\DBAL\Connection;
use TowerDNS\Application\Module\LocalModuleDiscovery;

/**
 * Lightweight, machine-readable health and readiness checks.
 *
 * Secrets and config values are never returned in the payload; only stable
 * statuses and safe labels are exposed.
 */
final readonly class HealthStatusService
{
    /**
     * @param string $projectRoot Absolute repository root used for writable-path checks.
     */
    public function __construct(
        private ?Connection $connection = null,
        private string $projectRoot = '',
        private ?string $configPath = null,
        private ?string $providerConfigPath = null,
        private ?LocalModuleDiscovery $moduleDiscovery = null,
    ) {}

    /**
     * @return array{status: string, ready: bool, checks: array<string, array{ok: bool, summary: string, details?: array<string, string>}>}
     */
    public function check(): array
    {
        $checks = [];

        $this->addCheck($checks, 'php_extensions', $this->phpExtensionsOk());
        $this->addCheck($checks, 'database', $this->databaseOk());
        $this->addCheck($checks, 'config', $this->configOk());
        $this->addCheck($checks, 'provider_config', $this->providerConfigOk());
        $this->addCheck($checks, 'writable_paths', $this->writablePathsOk());
        $this->addCheck($checks, 'module_discovery', $this->moduleDiscoveryOk());
        $this->addCheck($checks, 'schema', $this->schemaOk());
        $ready = array_all($checks, fn(array $check): bool => $check['ok']);
        return [
            'status' => $ready ? 'ok' : 'degraded',
            'ready'  => $ready,
            'checks' => $checks,
        ];
    }

    /**
     * @return array{status: string, ready: bool, checks: array<string, array{ok: bool, summary: string, details?: array<string, string>}>}
     */
    public function readiness(): array
    {
        $payload = $this->check();
        if ($payload['ready']) {
            return $payload;
        }

        return [
            'status' => 'fail',
            'ready'  => false,
            'checks' => $payload['checks'],
        ];
    }

    /**
     * @param array<string, array{ok: bool, summary: string, details?: array<string, string>}> $checks
     * @param array<string, string> $details
     */
    private function addCheck(array &$checks, string $name, bool $ok, string $summary = '', array $details = []): void
    {
        $checks[$name] = [
            'ok'      => $ok,
            'summary' => $summary === '' ? ($ok ? 'ok' : 'unhealthy') : $summary,
            'details' => $details,
        ];
    }

    private function phpExtensionsOk(): bool
    {
        return array_all(['pdo', 'openssl', 'sodium', 'intl', 'mbstring'], fn(string $extension): bool => extension_loaded($extension));
    }

    private function databaseOk(): bool
    {
        if (!$this->connection instanceof \Doctrine\DBAL\Connection) {
            return false;
        }

        try {
            $this->connection->executeQuery('SELECT 1');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function configOk(): bool
    {
        $path = $this->configPath ?? ($this->projectRoot !== '' ? $this->projectRoot . '/configs/config.local.toml' : '');
        if ($path === '' || !is_file($path)) {
            return false;
        }

        try {
            $raw = file_get_contents($path);
            if ($raw === false || $raw === '') {
                return false;
            }

            $decoded = Toml::decode($raw, asArray: true);
            if (!is_array($decoded)) {
                return false;
            }

            /** @var array<string, mixed> $data */
            $data = $decoded;
            $key  = (string) ($data['security']['encryption_key'] ?? '');
            return $key !== '' && strlen(base64_decode($key, true) ?: '') === 32;
        } catch (\Throwable) {
            return false;
        }
    }

    private function providerConfigOk(): bool
    {
        $path = $this->providerConfigPath ?? ($this->projectRoot !== '' ? $this->projectRoot . '/configs/providers.toml' : '');
        if ($path === '') {
            return true;
        }

        if (!is_file($path)) {
            return true;
        }

        try {
            $raw = file_get_contents($path);
            if ($raw === false || $raw === '') {
                return true;
            }

            $decoded = Toml::decode($raw, asArray: true);
            return is_array($decoded);
        } catch (\Throwable) {
            return false;
        }
    }

    private function writablePathsOk(): bool
    {
        $paths = [
            $this->projectRoot !== '' ? $this->projectRoot . '/data' : null,
            $this->projectRoot !== '' ? $this->projectRoot . '/cache/ratelimit' : null,
            $this->projectRoot !== '' ? $this->projectRoot . '/logs' : null,
        ];

        foreach ($paths as $path) {
            if ($path === null) {
                continue;
            }
            if (!is_dir($path) && !mkdir($path, 0o750, true) && !is_dir($path)) {
                return false;
            }
            if (!is_writable($path)) {
                return false;
            }
        }

        return true;
    }

    private function moduleDiscoveryOk(): bool
    {
        if (!$this->moduleDiscovery instanceof \TowerDNS\Application\Module\LocalModuleDiscovery) {
            return true;
        }

        try {
            $this->moduleDiscovery->discover();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function schemaOk(): bool
    {
        if (!$this->connection instanceof \Doctrine\DBAL\Connection) {
            return false;
        }

        foreach (['users', 'accounts', 'account_memberships', 'provider_accounts', 'managed_zones'] as $table) {
            try {
                $exists = $this->connection->createSchemaManager()->tablesExist([$table]);
                if (!$exists) {
                    return false;
                }
            } catch (\Throwable) {
                return false;
            }
        }

        return true;
    }
}

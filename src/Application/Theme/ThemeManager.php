<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Theme;

/**
 * Discovers and validates themes stored below themes/{name}/theme.json.
 *
 * Theme names never become paths before they have passed a strict allow-list
 * and matched a discovered manifest. Invalid configuration therefore falls
 * back to the bundled default theme without enabling path traversal.
 */
final class ThemeManager
{
    /** @var array<string, Theme>|null */
    private ?array $themes = null;

    public function __construct(
        private readonly string $projectRoot,
        private readonly string $configuredTheme = 'default',
    ) {}

    public function getActive(?string $preferredTheme = null): Theme
    {
        $requested = $preferredTheme;
        if (in_array($requested, [null, '', 'system'], true)) {
            $requested = $this->configuredTheme;
        }

        $themes = $this->getAvailable();

        return $themes[$requested] ?? $themes['default'];
    }

    public function has(string $name): bool
    {
        return isset($this->getAvailable()[$name]);
    }

    /** @return array<string, Theme> */
    public function getAvailable(): array
    {
        if ($this->themes !== null) {
            return $this->themes;
        }

        $themesDir = $this->projectRoot . '/themes';
        $themes    = [];

        if (is_dir($themesDir)) {
            foreach (scandir($themesDir) ?: [] as $name) {
                if (!$this->isValidName($name)) {
                    continue;
                }

                $theme = $this->readTheme($themesDir, $name);
                if ($theme instanceof Theme) {
                    $themes[$name] = $theme;
                }
            }
        }

        $themes['default'] ??= new Theme(
            'default',
            'TowerDNS Default',
            'Bundled fallback theme.',
            'cerberus',
        );

        ksort($themes);
        $this->themes = $themes;

        return $this->themes;
    }

    private function isValidName(string $name): bool
    {
        return preg_match('/\A[a-z0-9][a-z0-9_-]{0,63}\z/', $name) === 1;
    }

    private function readTheme(string $themesDir, string $name): ?Theme
    {
        $manifest = $themesDir . '/' . $name . '/theme.json';
        if (!is_file($manifest)) {
            return null;
        }

        $json = file_get_contents($manifest);
        if ($json === false) {
            return null;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return null;
        }

        $skeletonTheme = (string) ($decoded['skeleton_theme'] ?? '');
        if (!$this->isValidName($skeletonTheme)) {
            return null;
        }

        return new Theme(
            $name,
            trim((string) ($decoded['name'] ?? '')) ?: $name,
            trim((string) ($decoded['description'] ?? '')),
            $skeletonTheme,
        );
    }
}

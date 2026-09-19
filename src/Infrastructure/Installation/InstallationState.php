<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Installation;

/**
 * Resolves whether an installation has already completed.
 *
 * This check deliberately has no framework or Composer dependency so the
 * public front controller and the installer can use the same rule even while
 * the application autoloader is unavailable.
 */
final class InstallationState
{
    public static function isLocked(string $projectRoot): bool
    {
        $installDir = rtrim($projectRoot, '/\\') . '/install';

        return is_file($projectRoot . '/configs/.installed')
            || is_file($installDir . '/.lock')
            || (!is_dir($installDir)
                && is_file($projectRoot . '/configs/config.local.toml')
                && is_file($projectRoot . '/configs/database.toml')
                && is_file($projectRoot . '/configs/providers.toml'));
    }
}

<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Services;

/**
 * No-op checker — used when HIBP integration is disabled in config.
 */
final class NullBreachedPasswordChecker implements BreachedPasswordCheckerInterface
{
    public function timesSeen(string $password): int
    {
        return 0;
    }
}

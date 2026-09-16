<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Installation;

/**
 * Validated inputs for creating the first usable TowerDNS installation state.
 *
 * The request deliberately contains no transport concerns, so the same
 * bootstrap operation can be used by the CLI installer, web installer, and
 * future administrative tooling.
 */
final readonly class FreshInstallBootstrapRequest
{
    public function __construct(
        public string $adminId,
        public string $adminEmail,
        public string $adminPasswordHash,
        public string $adminDisplayName,
        public string $accountName,
        public string $accountSlug,
        public string $createdAt,
    ) {}
}

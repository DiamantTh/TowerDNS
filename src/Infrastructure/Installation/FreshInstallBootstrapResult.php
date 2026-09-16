<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Installation;

/**
 * Describes whether this call created a new initial user/account or only
 * verified and completed the idempotent bootstrap seeds.
 */
final readonly class FreshInstallBootstrapResult
{
    public function __construct(
        public bool $createdInitialState,
        public string $adminId,
    ) {}
}

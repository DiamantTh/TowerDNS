<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\DTO;

final readonly class ProviderSummaryDTO
{
    /**
     * @param array<string, bool> $capabilities
     */
    public function __construct(
        public string $id,
        public string $displayName,
        public array $capabilities,
    ) {}
}

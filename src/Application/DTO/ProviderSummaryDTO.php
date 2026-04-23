<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\DTO;

final class ProviderSummaryDTO
{
    /**
     * @param array<string, bool> $capabilities
     */
    public function __construct(
        public readonly string $id,
        public readonly string $displayName,
        public readonly array $capabilities
    ) {
    }
}

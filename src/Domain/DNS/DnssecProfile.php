<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Domain\DNS;

final readonly class DnssecProfile
{
    /**
     * @param array<string, bool> $features
     * @param array<string, scalar|null> $metadata
     */
    public function __construct(
        public string $zoneId,
        public DnssecState $state,
        public array $features = [],
        public array $metadata = [],
    ) {}
}

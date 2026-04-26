<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Domain\DNS;

final readonly class Zone
{
    /**
     * @param array<string, scalar|null> $tags
     * @param array<string, scalar|null> $metadata
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $providerId,
        public bool $active,
        public array $tags = [],
        public array $metadata = [],
    ) {}
}

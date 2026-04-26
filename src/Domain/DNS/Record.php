<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Domain\DNS;

final readonly class Record
{
    /**
     * @param array<string, scalar|null> $metadata
     */
    public function __construct(
        public string $id,
        public string $zoneId,
        public string $name,
        public RecordType $type,
        public int $ttl,
        public string $content,
        public ?string $comment = null,
        public array $metadata = [],
    ) {}
}

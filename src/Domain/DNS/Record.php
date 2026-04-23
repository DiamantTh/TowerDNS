<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Domain\DNS;

final class Record
{
    /**
     * @param array<string, scalar|null> $metadata
     */
    public function __construct(
        public readonly string $id,
        public readonly string $zoneId,
        public readonly string $name,
        public readonly RecordType $type,
        public readonly int $ttl,
        public readonly string $content,
        public readonly ?string $comment = null,
        public readonly array $metadata = []
    ) {
    }
}

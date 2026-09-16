<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Domain\DNS;

/** A DNS resource-record set. TTL belongs to this aggregate, not one RDATA. */
final readonly class Rrset
{
    /**
     * @param non-empty-list<string> $rdata
     * @param array<string, scalar|null> $metadata
     */
    public function __construct(
        public string $zoneId,
        public string $ownerName,
        public DNSRecordType $type,
        public int $ttl,
        public array $rdata,
        public ?string $providerIdentity = null,
        public array $metadata = [],
    ) {
        if ($rdata === []) {
            throw new \InvalidArgumentException('Ein RRset muss mindestens einen RDATA-Wert enthalten.');
        }
    }
}

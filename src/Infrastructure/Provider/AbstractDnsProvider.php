<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Provider;

use TowerDNS\Application\Contracts\DnsProviderInterface;
use TowerDNS\Application\Contracts\ProviderCapabilitySet;

/**
 * Convenience base for provider adapters.
 *
 * Concrete adapters declare a capability map; this base class wraps it in an
 * immutable {@see ProviderCapabilitySet}.
 */
abstract class AbstractDnsProvider implements DnsProviderInterface
{
    private readonly ProviderCapabilitySet $capabilitySet;

    public function __construct()
    {
        $this->capabilitySet = new ProviderCapabilitySet($this->capabilityMap());
    }

    /**
     * @return array<string, bool>
     */
    abstract protected function capabilityMap(): array;

    public function capabilities(): ProviderCapabilitySet
    {
        return $this->capabilitySet;
    }

    /**
     * Stable, 12-character hex fingerprint of a record content string.
     *
     * Used to build per-record IDs that are unique within an RRset while
     * remaining deterministic across list → update/delete round-trips.
     * 12 hex chars = 48 bits of entropy — sufficient to distinguish records
     * inside a single RRset (typically ≤ 20 entries).
     */
    protected static function contentHash(string $content): string
    {
        return substr(hash('sha256', $content), 0, 12);
    }
}

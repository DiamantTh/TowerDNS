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
    private ProviderCapabilitySet $capabilitySet;

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
}

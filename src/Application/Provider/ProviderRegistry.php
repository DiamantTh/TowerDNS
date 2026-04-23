<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Provider;

use TowerDNS\Application\Contracts\DnsProviderInterface;
use TowerDNS\Application\DTO\ProviderSummaryDTO;
use TowerDNS\Application\Exception\ProviderNotFoundException;

/**
 * Registry of provider adapters, keyed by their stable {@see DnsProviderInterface::id()}.
 *
 * The registry is the single point through which the Application layer obtains
 * provider instances. Adapters are never injected into services directly so
 * the same workflow code can drive any registered provider.
 */
final class ProviderRegistry
{
    /** @var array<string, DnsProviderInterface> */
    private array $providers = [];

    /**
     * @param iterable<DnsProviderInterface> $providers
     */
    public function __construct(iterable $providers = [])
    {
        foreach ($providers as $provider) {
            $this->register($provider);
        }
    }

    public function register(DnsProviderInterface $provider): void
    {
        $this->providers[$provider->id()] = $provider;
    }

    public function get(string $providerId): DnsProviderInterface
    {
        if (!isset($this->providers[$providerId])) {
            throw ProviderNotFoundException::forId($providerId);
        }

        return $this->providers[$providerId];
    }

    public function has(string $providerId): bool
    {
        return isset($this->providers[$providerId]);
    }

    /**
     * @return list<DnsProviderInterface>
     */
    public function all(): array
    {
        return array_values($this->providers);
    }

    /**
     * @return list<ProviderSummaryDTO>
     */
    public function summaries(): array
    {
        $out = [];
        foreach ($this->providers as $provider) {
            $out[] = new ProviderSummaryDTO(
                $provider->id(),
                $provider->displayName(),
                $provider->capabilities()->all(),
            );
        }

        return $out;
    }
}

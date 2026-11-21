<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Provider;

use TowerDNS\Application\Contracts\ConditionalRrsetProviderInterface;
use TowerDNS\Application\Contracts\DNSProviderInterface;
use TowerDNS\Application\Contracts\ProviderConstraintProviderInterface;
use TowerDNS\Application\Contracts\TargetedRrsetMutationProviderInterface;
use TowerDNS\Application\DTO\ProviderSummaryDTO;
use TowerDNS\Application\Exception\ProviderNotFoundException;

/**
 * Registry of provider adapters, keyed by their stable {@see DNSProviderInterface::id()}.
 *
 * The registry is the single point through which the Application layer obtains
 * provider instances. Adapters are never injected into services directly so
 * the same workflow code can drive any registered provider.
 * @psalm-api Public provider-module/application integration contract.
 */
final class ProviderRegistry
{
    /** @var array<string, DNSProviderInterface> */
    private array $providers = [];

    /**
     * @param iterable<DNSProviderInterface> $providers
     */
    public function __construct(iterable $providers = [])
    {
        foreach ($providers as $provider) {
            $this->register($provider);
        }
    }

    public function register(DNSProviderInterface $provider): void
    {
        $this->providers[$provider->id()] = $provider;
    }

    public function get(string $providerId): DNSProviderInterface
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
     * @return list<DNSProviderInterface>
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
            $profile     = $provider instanceof ProviderConstraintProviderInterface ? $provider->constraints() : null;
            $constraints = $profile instanceof \TowerDNS\Application\Contracts\ProviderConstraintProfile ? [
                'rrset_write_mode'      => $profile->rrsetWriteMode,
                'read_back_consistency' => $profile->readBackConsistency,
                'rfc3597_write'         => $profile->supportsRfc3597Write,
                ...$profile->details,
            ] : [];
            if ($provider instanceof ConditionalRrsetProviderInterface) {
                $constraints['conditional_rrset_write'] = true;
            }
            if ($provider instanceof TargetedRrsetMutationProviderInterface) {
                $mutation                       = $provider->rrsetMutationCapabilities();
                $constraints['rrset_mutations'] = array_values(array_filter([
                    $mutation->appendRecord ? 'append_record' : null,
                    $mutation->removeRecord ? 'remove_record' : null,
                ]));
                $constraints['rrset_multi_record_atomic']  = $mutation->atomicMultiRecord;
                $constraints['rrset_mutation_consistency'] = $mutation->consistency;
            }
            $out[] = new ProviderSummaryDTO(
                $provider->id(),
                $provider->displayName(),
                $provider->capabilities()->all(),
                $constraints,
            );
        }

        return $out;
    }
}

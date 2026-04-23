<?php

declare(strict_types=1);

namespace TowerDNS\Application\Contracts;

final class ProviderCapabilitySet
{
    /**
     * @param array<string, bool> $capabilities
     */
    public function __construct(private readonly array $capabilities)
    {
    }

    public function supports(string $capability): bool
    {
        return $this->capabilities[$capability] ?? false;
    }

    /**
     * @return array<string, bool>
     */
    public function all(): array
    {
        return $this->capabilities;
    }
}

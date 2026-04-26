<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Contracts;

final readonly class ProviderCapabilitySet
{
    /**
     * @param array<string, bool> $capabilities
     */
    public function __construct(private array $capabilities) {}

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

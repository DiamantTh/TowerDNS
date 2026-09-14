<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Repository;

/**
 * Persistence boundary for deliberately system-wide provider configuration.
 * Account-owned provider credentials belong exclusively in ProviderAccount.
 */
interface SystemProviderConfigurationStoreInterface
{
    /** @return array<string, mixed> */
    public function load(): array;

    /** @param array<string, mixed> $configuration */
    public function save(array $configuration): void;

    /**
     * Applies a configuration mutation while preventing concurrent writers
     * from silently overwriting each other.
     *
     * @param callable(array<string, mixed>): array<string, mixed> $mutator
     */
    public function update(callable $mutator): void;
}

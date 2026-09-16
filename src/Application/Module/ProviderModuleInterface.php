<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Module;

use TowerDNS\Application\Contracts\DNSProviderInterface;

/** Common contribution contract for provider modules discovered locally. */
interface ProviderModuleInterface extends TowerDNSModuleInterface
{
    public function providerDefinition(): ProviderDefinition;

    /** @param array<string, mixed> $credentials */
    public function buildProvider(array $credentials): DNSProviderInterface;
}

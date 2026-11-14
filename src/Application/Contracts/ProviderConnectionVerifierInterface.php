<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Contracts;

use TowerDNS\Application\DTO\ProviderConnectionVerification;

/** Performs a provider-specific read-only connectivity check for transient credentials. */
interface ProviderConnectionVerifierInterface
{
    /** @param array<string, string> $credentials */
    public function verify(string $providerType, array $credentials): ProviderConnectionVerification;
}

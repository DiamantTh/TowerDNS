<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Contracts;

use TowerDNS\Domain\Account\ProviderAccount;

/**
 * Creates a {@see DNSProviderInterface} instance from a {@see ProviderAccount}.
 *
 * The factory decrypts stored credentials and builds the appropriate provider
 * adapter. Implementations live in the Infrastructure layer.
 */
interface AccountProviderFactoryInterface
{
    /**
     * @throws \TowerDNS\Application\Exception\ProviderNotFoundException if provider type is unknown
     * @throws \RuntimeException if credentials cannot be decrypted or are malformed
     */
    public function buildProvider(ProviderAccount $account): DNSProviderInterface;
}

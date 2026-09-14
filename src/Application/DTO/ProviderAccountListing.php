<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\DTO;

use TowerDNS\Domain\Account\Account;
use TowerDNS\Domain\Account\ProviderAccount;

/** Read model shared by web, CLI, and a future API boundary. */
final readonly class ProviderAccountListing
{
    /**
     * @param list<ProviderAccount> $providerAccounts
     * @param list<string>          $allowedTypes
     */
    public function __construct(
        public Account $account,
        public array $providerAccounts,
        public array $allowedTypes,
    ) {}
}

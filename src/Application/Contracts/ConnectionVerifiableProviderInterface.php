<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Contracts;

use TowerDNS\Application\DTO\ProviderConnectionVerification;

/** Optional capability for adapters with a bounded, read-only auth endpoint. */
interface ConnectionVerifiableProviderInterface
{
    public function verifyConnection(): ProviderConnectionVerification;
}

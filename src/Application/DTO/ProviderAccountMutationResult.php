<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\DTO;

/** Non-secret result data used by a boundary to write an audit event. */
final readonly class ProviderAccountMutationResult
{
    public function __construct(
        public int $accountId,
        public int $providerAccountId,
        public string $providerType,
        public ?string $name = null,
    ) {}
}

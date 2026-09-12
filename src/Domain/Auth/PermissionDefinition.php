<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Domain\Auth;

/** Immutable metadata for a centrally registered technical permission. */
final readonly class PermissionDefinition
{
    /** @param list<string> $scopeKinds */
    public function __construct(
        public string $id,
        public string $label,
        public ?string $description = null,
        public array $scopeKinds = ['system', 'account', 'zone'],
    ) {}
}

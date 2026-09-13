<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Domain\Auth;

/** Immutable metadata for a centrally registered technical permission. */
final readonly class PermissionDefinition
{
    /**
     * Labels and descriptions are translation keys, never user-facing source
     * text. Modules use the same convention for their contributions.
     *
     * @param list<'system'|'account'|'zone'> $scopeKinds
     */
    public function __construct(
        public string $id,
        public string $label,
        public ?string $description = null,
        public array $scopeKinds = ['system', 'account', 'zone'],
    ) {
        foreach ($scopeKinds as $scopeKind) {
            if (!in_array($scopeKind, ['system', 'account', 'zone'], true)) {
                throw new \InvalidArgumentException(sprintf('Ungültiger Permission-Scope: %s', $scopeKind));
            }
        }
    }
}

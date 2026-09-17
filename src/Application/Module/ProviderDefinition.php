<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Module;

/** Provider metadata and its credential schema, supplied by a provider module. */
final readonly class ProviderDefinition
{
    /**
     * @param array<string, array{input: string, label: string, required: bool, secret: bool, default?: string, type?: string}> $credentials
     *        Credential labels are translator keys owned by the contributing module.
     */
    public function __construct(
        public string $id,
        public string $displayName,
        public bool $userManaged,
        public array $credentials,
        public bool $systemConfigurable = true,
    ) {
        if (preg_match('/^[a-z][a-z0-9-]*$/D', $id) !== 1) {
            throw new \InvalidArgumentException('Provider IDs must be lowercase.');
        }
    }
}

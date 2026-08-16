<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Theme;

/**
 * Validated, immutable theme metadata exposed to the Svelte application.
 */
final readonly class Theme
{
    public function __construct(
        public string $name,
        public string $displayName,
        public string $description,
        public string $skeletonTheme,
    ) {}

    /** @return array{name: string, displayName: string, description: string, skeletonTheme: string} */
    public function toArray(): array
    {
        return [
            'name'          => $this->name,
            'displayName'   => $this->displayName,
            'description'   => $this->description,
            'skeletonTheme' => $this->skeletonTheme,
        ];
    }
}

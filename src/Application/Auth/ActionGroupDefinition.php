<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Auth;

/** UI metadata that groups technical permissions without becoming a grant. */
final readonly class ActionGroupDefinition
{
    /** @param non-empty-list<string> $permissionIds */
    public function __construct(
        public string $id,
        public string $label,
        public ?string $description,
        public array $permissionIds,
    ) {}
}

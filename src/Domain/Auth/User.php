<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Domain\Auth;

final readonly class User
{
    /**
     * @param list<Role> $roles
     */
    public function __construct(
        public string $id,
        public string $email,
        public array $roles = [],
        public ?string $displayName = null,
        public string $theme = 'system',
        public string $locale = 'en-GB',
        public ?string $lastLoginAt = null,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
    ) {}

    public function hasPermission(Permission|string $permission): bool
    {
        return array_any($this->roles, fn(Role $role): bool => $role->has($permission));
    }
}

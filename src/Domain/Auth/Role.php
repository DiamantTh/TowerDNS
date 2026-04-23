<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Domain\Auth;

final class Role
{
    /**
     * @param list<Permission> $permissions
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $permissions
    ) {
    }

    public function has(Permission $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }
}

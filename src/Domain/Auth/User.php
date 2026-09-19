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
        public bool $active = true,
        public ?string $displayName = null,
        public string $theme = 'system',
        public string $language = 'en-GB',
        public string $locale = 'en-GB',
        public string $timezone = 'UTC',
        public ?string $firstName = null,
        public ?string $lastName = null,
        public ?string $alternateEmail = null,
        public ?string $phone = null,
        public ?string $mobile = null,
        public ?string $street = null,
        public ?string $street2 = null,
        public ?string $postalCode = null,
        public ?string $city = null,
        public ?string $region = null,
        public ?string $country = null,
        public ?string $externalReference = null,
        public ?string $lastLoginAt = null,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
    ) {}

    public function hasPermission(Permission|string $permission): bool
    {
        return array_any($this->roles, fn(Role $role): bool => $role->has($permission));
    }
}

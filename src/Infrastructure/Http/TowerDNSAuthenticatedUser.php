<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http;

use Mezzio\Authentication\UserInterface;
use TowerDNS\Domain\Auth\User;

/**
 * Mezzio's request identity backed by the canonical TowerDNS domain user.
 *
 * No profile, credential, MFA, or authorization details are exposed through
 * the generic details bag. Domain authorization continues to use the attached
 * User instance and the scoped Application services.
 */
final readonly class TowerDNSAuthenticatedUser implements UserInterface
{
    public function __construct(private User $user) {}

    public function domainUser(): User
    {
        return $this->user;
    }

    #[\Override]
    public function getIdentity(): string
    {
        return $this->user->id;
    }

    #[\Override]
    public function getRoles(): iterable
    {
        foreach ($this->user->roles as $role) {
            yield $role->id;
        }
    }

    #[\Override]
    public function getDetail(string $name, $default = null)
    {
        return $default;
    }

    #[\Override]
    public function getDetails(): array
    {
        return [];
    }
}

<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Services;

use TowerDNS\Application\Exception\AuthorizationException;
use TowerDNS\Domain\Auth\Permission;
use TowerDNS\Domain\Auth\User;

final class AuthorizationService
{
    /**
     * Throws if the user does not hold the given permission.
     */
    public function assert(User $user, Permission $permission): void
    {
        if (!$this->isGranted($user, $permission)) {
            throw new AuthorizationException(
                sprintf('Benutzer hat kein Recht: %s', $permission->value)
            );
        }
    }

    /**
     * Returns true when any of the user's roles grants the permission.
     * Respects role hierarchy via {@see \TowerDNS\Domain\Auth\Role::hasPermission()}.
     */
    public function isGranted(User $user, Permission $permission): bool
    {
        return $user->hasPermission($permission);
    }
}

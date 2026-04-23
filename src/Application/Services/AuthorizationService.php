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
    public function assert(User $user, Permission $permission): void
    {
        if (!$user->hasPermission($permission)) {
            throw new AuthorizationException(sprintf('Benutzer hat kein Recht: %s', $permission->value));
        }
    }
}

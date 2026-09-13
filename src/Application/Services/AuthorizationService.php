<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Services;

use TowerDNS\Application\Exception\AuthorizationException;
use TowerDNS\Domain\Auth\Permission;
use TowerDNS\Domain\Auth\PermissionRegistry;
use TowerDNS\Domain\Auth\User;

final readonly class AuthorizationService
{
    private RbacPermissionChecker $rbac;
    private PermissionRegistry $permissions;

    public function __construct(?RbacPermissionChecker $rbac = null, ?PermissionRegistry $permissions = null)
    {
        $this->rbac        = $rbac        ?? new RbacPermissionChecker();
        $this->permissions = $permissions ?? new PermissionRegistry();
    }

    /**
     * Throws if the user does not hold the given permission.
     */
    public function assert(User $user, Permission|string $permission): void
    {
        if (!$this->isGranted($user, $permission)) {
            throw new AuthorizationException(
                sprintf('Benutzer hat kein Recht: %s', $this->permissions->assertKnown($permission))
            );
        }
    }

    /**
     * Returns true when any of the user's roles grants the permission.
     * Respects role hierarchy via {@see \TowerDNS\Domain\Auth\Role::hasPermission()}.
     */
    public function isGranted(User $user, Permission|string $permission): bool
    {
        $id = $this->permissions->assertKnown($permission);
        return $this->rbac->isGrantedByAny($user->roles, $id);
    }
}

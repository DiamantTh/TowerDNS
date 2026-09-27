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
        if (!$user->active) {
            return false;
        }
        $id = $this->permissions->assertKnown($permission);
        return $this->rbac->isGrantedByAny($user->roles, $id, $this->permissions);
    }

    /** Only the shipped, built-in superadmin role has global operator authority. */
    public function isBuiltInSuperadmin(User $user): bool
    {
        if (!$user->active) {
            return false;
        }
        return array_any($user->roles, fn(\TowerDNS\Domain\Auth\Role $role): bool => $role->isBuiltInSuperadmin());
    }

    /** @return list<string> Effective currently registered permissions. */
    public function grantedPermissionIds(User $user): array
    {
        $granted = [];
        foreach ($this->permissions->ids() as $permissionId) {
            if ($this->isGranted($user, $permissionId)) {
                $granted[] = $permissionId;
            }
        }

        return $granted;
    }
}

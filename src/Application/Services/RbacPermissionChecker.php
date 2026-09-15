<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Services;

use Laminas\Permissions\Rbac\RoleInterface;
use TowerDNS\Domain\Auth\PermissionRegistry;
use TowerDNS\Domain\Auth\Role;

/**
 * Thin, stateless adapter around Laminas RBAC roles.
 *
 * TowerDNS resolves a user's dynamic roles and their account/zone scope per
 * request. Laminas' Rbac object is a mutable role registry without a removal
 * API, so retaining one here would leak request-specific role objects in
 * long-running workers. RoleInterface already supplies the same permission
 * and child-role semantics needed after TowerDNS has resolved a role.
 */
final class RbacPermissionChecker
{
    public function isGranted(RoleInterface $role, string $permission, ?PermissionRegistry $permissions = null): bool
    {
        if ($role instanceof Role && $role->isBuiltInSuperadmin() && $permissions?->has($permission)) {
            return true;
        }

        return $role->hasPermission($permission);
    }

    /**
     * @param iterable<RoleInterface> $roles
     */
    public function isGrantedByAny(iterable $roles, string $permission, ?PermissionRegistry $permissions = null): bool
    {
        foreach ($roles as $role) {
            if ($this->isGranted($role, $permission, $permissions)) {
                return true;
            }
        }

        return false;
    }
}

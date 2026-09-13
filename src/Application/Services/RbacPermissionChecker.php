<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Services;

use Laminas\Permissions\Rbac\RoleInterface;

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
    public function isGranted(RoleInterface $role, string $permission): bool
    {
        return $role->hasPermission($permission);
    }

    /**
     * @param iterable<RoleInterface> $roles
     */
    public function isGrantedByAny(iterable $roles, string $permission): bool
    {
        foreach ($roles as $role) {
            if ($this->isGranted($role, $permission)) {
                return true;
            }
        }

        return false;
    }
}

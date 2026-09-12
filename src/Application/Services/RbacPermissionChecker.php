<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Services;

use Laminas\Permissions\Rbac\Rbac;
use Laminas\Permissions\Rbac\RoleInterface;

/**
 * Thin adapter around Laminas RBAC.
 *
 * TowerDNS resolves account and zone scope itself. This service answers only
 * whether one already resolved role grants a technical permission.
 */
final class RbacPermissionChecker
{
    public function isGranted(RoleInterface $role, string $permission): bool
    {
        $rbac = new Rbac();
        $rbac->addRole($role);

        return $rbac->isGranted($role, $permission);
    }
}

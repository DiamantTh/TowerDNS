<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Domain\Auth;

use Laminas\Permissions\Rbac\RoleInterface;

final class Role implements RoleInterface
{
    /** @var array<string, true> */
    private array $permissionMap = [];

    /** @var array<string, RoleInterface> */
    private array $children = [];

    /** @var array<string, RoleInterface> */
    private array $parents = [];

    /**
     * @param list<Permission|string> $permissions
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        array $permissions = [],
        public readonly bool $isSystem = false,
    ) {
        foreach ($permissions as $perm) {
            $this->permissionMap[PermissionRegistry::normalize($perm instanceof Permission ? $perm->value : $perm)] = true;
        }
    }

    // ── Laminas RoleInterface ─────────────────────────────────────────────

    public function getName(): string
    {
        return $this->name;
    }

    public function addPermission(string $name): void
    {
        $this->permissionMap[PermissionRegistry::normalize($name)] = true;
    }

    public function hasPermission(string $name): bool
    {
        if (isset($this->permissionMap[$name])) {
            return true;
        }
        return array_any($this->children, fn(RoleInterface $child): bool => $child->hasPermission($name));
    }

    public function addChild(RoleInterface $child): void
    {
        $childName = $child->getName();
        if (!isset($this->children[$childName])) {
            $this->children[$childName] = $child;
            $child->addParent($this);
        }
    }

    public function getChildren(): iterable
    {
        return array_values($this->children);
    }

    public function addParent(RoleInterface $parent): void
    {
        $this->parents[$parent->getName()] = $parent;
    }

    public function getParents(): iterable
    {
        return array_values($this->parents);
    }

    // ── Domain convenience ────────────────────────────────────────────────

    public function has(Permission|string $permission): bool
    {
        return $this->hasPermission(PermissionRegistry::normalize($permission instanceof Permission ? $permission->value : $permission));
    }

    /** @return list<string> */
    public function getPermissionIds(): array
    {
        return array_keys($this->permissionMap);
    }

    /**
     * @return list<Permission>
     */
    public function getPermissions(): array
    {
        $result = [];
        foreach (array_keys($this->permissionMap) as $value) {
            $perm = Permission::tryFrom($value);
            if ($perm !== null) {
                $result[] = $perm;
            }
        }

        return $result;
    }
}

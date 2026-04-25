<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Domain\Auth;

use Laminas\Permissions\Rbac\RoleInterface;

final class Role implements RoleInterface
{
    /** @var array<string, true> */
    private array $permissionMap;

    /** @var array<string, RoleInterface> */
    private array $children = [];

    /** @var array<string, RoleInterface> */
    private array $parents = [];

    /**
     * @param list<Permission> $permissions
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        array $permissions = []
    ) {
        $this->permissionMap = [];
        foreach ($permissions as $perm) {
            $this->permissionMap[$perm->value] = true;
        }
    }

    // ── Laminas RoleInterface ─────────────────────────────────────────────

    public function getName(): string
    {
        return $this->name;
    }

    public function addPermission(string $name): void
    {
        $this->permissionMap[$name] = true;
    }

    public function hasPermission(string $name): bool
    {
        if (isset($this->permissionMap[$name])) {
            return true;
        }

        foreach ($this->children as $child) {
            if ($child->hasPermission($name)) {
                return true;
            }
        }

        return false;
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

    public function has(Permission $permission): bool
    {
        return $this->hasPermission($permission->value);
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

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
        /**
         * Built-in roles are maintained by TowerDNS and cannot be edited or
         * removed in the role editor. This is unrelated to the scope in which
         * a role is granted.
         */
        public readonly bool $isBuiltIn = false,
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

    /**
     * The built-in superadmin role is deliberately the only role whose
     * effective permissions are derived from the active registry.  It is not
     * a general administrator bypass: callers must still ask the registry for
     * a known, currently active permission first.
     */
    public function isBuiltInSuperadmin(): bool
    {
        return $this->isBuiltIn && $this->id === 'superadmin';
    }

    /** @return list<string> */
    public function getPermissionIds(): array
    {
        return array_keys($this->permissionMap);
    }
}

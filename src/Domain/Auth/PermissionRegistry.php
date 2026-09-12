<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Domain\Auth;

/** Central catalogue for Core and future local-module permission definitions. */
final class PermissionRegistry
{
    /** @var array<string, PermissionDefinition> */
    private array $definitions = [];

    public function __construct()
    {
        foreach (Permission::cases() as $permission) {
            $this->register(new PermissionDefinition($permission->value, $permission->value));
        }
    }

    public function register(PermissionDefinition $definition): void
    {
        $id = self::normalize($definition->id);
        if (isset($this->definitions[$id])) {
            throw new \LogicException(sprintf('Permission-ID-Kollision: %s', $definition->id));
        }
        $this->definitions[$id] = new PermissionDefinition($id, $definition->label, $definition->description, $definition->scopeKinds);
    }

    public function has(string|Permission $permission): bool
    {
        return isset($this->definitions[self::normalize($permission instanceof Permission ? $permission->value : $permission)]);
    }

    public function assertKnown(string|Permission $permission): string
    {
        $id = self::normalize($permission instanceof Permission ? $permission->value : $permission);
        if (!isset($this->definitions[$id])) {
            throw new \InvalidArgumentException(sprintf('Unbekannte Permission-ID: %s', $id));
        }
        return $id;
    }

    /** @return list<PermissionDefinition> */
    public function all(): array
    {
        return array_values($this->definitions);
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_keys($this->definitions);
    }

    public static function normalize(string $id): string
    {
        $id = strtolower(trim($id));
        if (preg_match('/^[a-z][a-z0-9]*(?:\.[a-z][a-z0-9-]*)+$/D', $id) !== 1) {
            throw new \InvalidArgumentException('Permission-ID muss klein geschrieben und punkt-namespaced sein.');
        }
        return $id;
    }
}

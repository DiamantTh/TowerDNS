<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Repository;

use TowerDNS\Domain\Auth\Role;

interface RoleRepositoryInterface
{
    /**
     * Returns the role with its full permission list, or null when not found.
     */
    public function findById(string $id): ?Role;

    /**
     * Loads multiple roles by their IDs in one operation.
     *
     * @param list<string> $ids
     * @return list<Role>
     */
    public function findByIds(array $ids): array;

    /**
     * Returns all roles ordered by ID.
     *
     * @return list<Role>
     */
    public function findAll(): array;

    /**
     * Creates or fully updates a role (upsert).
     *
     * When the role ID does not exist it is inserted with is_system = false.
     * System roles (is_system = true) may be updated by this method since
     * their permission set is managed programmatically; the is_system flag
     * itself is never cleared.
     */
    public function save(Role $role): void;

    /**
     * Deletes a non-system role and all its permission assignments.
     *
     * @throws \DomainException when the role is a system role
     */
    public function delete(string $roleId): void;
}

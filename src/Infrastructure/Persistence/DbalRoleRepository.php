<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Persistence;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use TowerDNS\Application\Repository\RoleRepositoryInterface;
use TowerDNS\Domain\Auth\Permission;
use TowerDNS\Domain\Auth\Role;

/**
 * Doctrine DBAL implementation of {@see RoleRepositoryInterface}.
 *
 * Permissions are fetched in a second query and merged into each Role, keeping
 * the query count to O(1) for bulk operations (findAll, findByIds).
 */
final readonly class DbalRoleRepository implements RoleRepositoryInterface
{
    public function __construct(private Connection $connection) {}

    public function findById(string $id): ?Role
    {
        $roles = $this->findByIds([$id]);
        return $roles[0] ?? null;
    }

    public function findByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, name, is_system FROM roles WHERE id IN (?) ORDER BY id',
            [$ids],
            [ArrayParameterType::STRING],
        );

        if ($rows === []) {
            return [];
        }

        return $this->hydrateRoles($rows);
    }

    public function findAll(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, name, is_system FROM roles ORDER BY id',
        );

        if ($rows === []) {
            return [];
        }

        return $this->hydrateRoles($rows);
    }

    public function save(Role $role): void
    {
        $exists = $this->connection->fetchOne(
            'SELECT id FROM roles WHERE id = ?',
            [$role->id],
        );

        $this->connection->beginTransaction();

        try {
            if ($exists === false) {
                $this->connection->insert('roles', [
                    'id'         => $role->id,
                    'name'       => $role->name,
                    'is_system'  => 0,
                    'created_at' => new \DateTimeImmutable()->format('Y-m-d H:i:s'),
                ]);
            } else {
                $this->connection->update(
                    'roles',
                    ['name' => $role->name],
                    ['id' => $role->id],
                );
            }

            // Replace the full permission set.
            $this->connection->delete('role_permissions', ['role_id' => $role->id]);

            foreach ($role->getPermissionIds() as $permission) {
                $this->connection->insert('role_permissions', [
                    'role_id'    => $role->id,
                    'permission' => $permission,
                ]);
            }

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    public function delete(string $roleId): void
    {
        $row = $this->connection->fetchAssociative(
            'SELECT is_system FROM roles WHERE id = ?',
            [$roleId],
        );

        if (!is_array($row)) {
            return; // nothing to delete
        }

        if ((bool) ($row['is_system'] ?? false)) {
            throw new \DomainException(sprintf('Built-in role cannot be deleted: %s', $roleId));
        }

        $this->connection->delete('roles', ['id' => $roleId]);
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Builds Role domain objects from raw role rows, loading their permissions
     * with a single bulk query.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<Role>
     */
    private function hydrateRoles(array $rows): array
    {
        $ids = array_map(static fn(array $r): string => (string) ($r['id'] ?? ''), $rows);

        $permRows = $this->connection->fetchAllAssociative(
            'SELECT role_id, permission FROM role_permissions WHERE role_id IN (?)',
            [$ids],
            [ArrayParameterType::STRING],
        );

        /** @var array<string, list<string>> $permsByRole */
        $permsByRole = [];
        foreach ($permRows as $pr) {
            $rid                 = (string) ($pr['role_id'] ?? '');
            $permsByRole[$rid][] = (string) ($pr['permission'] ?? '');
        }

        $roles = [];
        foreach ($rows as $row) {
            $rid     = (string) ($row['id'] ?? '');
            $roles[] = new Role(
                $rid,
                (string) ($row['name'] ?? ''),
                $permsByRole[$rid] ?? [],
                isBuiltIn: (bool) ($row['is_system'] ?? false),
            );
        }

        return $roles;
    }
}

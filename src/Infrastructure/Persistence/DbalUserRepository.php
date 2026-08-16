<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Persistence;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use TowerDNS\Application\Repository\UserRepositoryInterface;
use TowerDNS\Domain\Auth\Permission;
use TowerDNS\Domain\Auth\Role;
use TowerDNS\Domain\Auth\User;

/**
 * Doctrine DBAL implementation of {@see UserRepositoryInterface}.
 *
 * Roles and their permissions are loaded eagerly alongside every User via two
 * additional queries (role-join + bulk permission fetch).  This keeps N+1
 * queries out of callers that iterate user lists while avoiding a single
 * cartesian JOIN that would inflate the row count.
 */
final readonly class DbalUserRepository implements UserRepositoryInterface
{
    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
    ) {}

    public function findById(string $id): ?User
    {
        $raw = $this->connection->fetchAssociative(
            'SELECT id, email, display_name, theme FROM users WHERE id = ? AND active = 1',
            [$id],
        );

        if (!is_array($raw)) {
            return null;
        }

        return new User(
            (string) ($raw['id'] ?? ''),
            (string) ($raw['email'] ?? ''),
            $this->loadRolesForUser((string) ($raw['id'] ?? '')),
            isset($raw['display_name']) && $raw['display_name'] !== '' ? (string) $raw['display_name'] : null,
            (string) ($raw['theme'] ?? 'system'),
        );
    }

    public function findByEmail(string $email): ?User
    {
        $raw = $this->connection->fetchAssociative(
            'SELECT id, email, display_name, theme FROM users WHERE email = ? AND active = 1',
            [$email],
        );

        if (!is_array($raw)) {
            return null;
        }

        return new User(
            (string) ($raw['id'] ?? ''),
            (string) ($raw['email'] ?? ''),
            $this->loadRolesForUser((string) ($raw['id'] ?? '')),
            isset($raw['display_name']) && $raw['display_name'] !== '' ? (string) $raw['display_name'] : null,
            (string) ($raw['theme'] ?? 'system'),
        );
    }

    public function fetchPasswordHash(string $email): ?string
    {
        $hash = $this->connection->fetchOne(
            'SELECT password_hash FROM users WHERE email = ? AND active = 1',
            [$email],
        );

        return is_string($hash) && $hash !== '' ? $hash : null;
    }

    public function fetchTotpSecret(string $userId): ?string
    {
        $secret = $this->connection->fetchOne(
            'SELECT totp_secret FROM users WHERE id = ?',
            [$userId],
        );

        return is_string($secret) && $secret !== '' ? $secret : null;
    }

    public function saveTotpSecret(string $userId, ?string $secret): void
    {
        $this->connection->update(
            'users',
            ['totp_secret' => $secret, 'updated_at' => $this->clock->now()->format('Y-m-d H:i:s')],
            ['id'          => $userId],
        );
    }

    public function updateLastLoginAt(string $userId): void
    {
        $this->connection->update(
            'users',
            ['last_login_at' => $this->clock->now()->format('Y-m-d H:i:s')],
            ['id'            => $userId],
        );
    }

    public function create(string $id, string $email, string $passwordHash): void
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $this->connection->insert('users', [
            'id'            => $id,
            'email'         => $email,
            'password_hash' => $passwordHash,
            'totp_secret'   => null,
            'active'        => true,
            'theme'         => 'system',
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);
    }

    public function updatePasswordHash(string $userId, string $passwordHash): void
    {
        $this->connection->update(
            'users',
            [
                'password_hash' => $passwordHash,
                'updated_at'    => $this->clock->now()->format('Y-m-d H:i:s'),
            ],
            ['id' => $userId],
        );
    }

    public function updateDisplayName(string $userId, string $displayName): void
    {
        $this->connection->update(
            'users',
            [
                'display_name' => $displayName !== '' ? $displayName : null,
                'updated_at'   => $this->clock->now()->format('Y-m-d H:i:s'),
            ],
            ['id' => $userId],
        );
    }

    public function updateTheme(string $userId, string $theme): void
    {
        $this->connection->update(
            'users',
            [
                'theme'      => $theme,
                'updated_at' => $this->clock->now()->format('Y-m-d H:i:s'),
            ],
            ['id' => $userId],
        );
    }

    public function syncRoles(string $userId, array $roleIds): void
    {
        $this->connection->beginTransaction();

        try {
            $this->connection->delete('user_roles', ['user_id' => $userId]);

            foreach ($roleIds as $roleId) {
                $this->connection->insert('user_roles', [
                    'user_id' => $userId,
                    'role_id' => $roleId,
                ]);
            }

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    public function findAll(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, email, display_name, theme FROM users WHERE active = 1 ORDER BY email',
        );

        if ($rows === []) {
            return [];
        }

        // Load roles for all users in two queries to avoid N+1.
        $ids = array_map(static fn(array $r): string => (string) ($r['id'] ?? ''), $rows);

        /** @var array<string, list<Role>> $rolesByUser */
        $rolesByUser = $this->loadRolesForUsers($ids);

        $users = [];
        foreach ($rows as $row) {
            $uid     = (string) ($row['id'] ?? '');
            $dn      = isset($row['display_name']) && $row['display_name'] !== '' ? (string) $row['display_name'] : null;
            $users[] = new User(
                $uid,
                (string) ($row['email'] ?? ''),
                $rolesByUser[$uid] ?? [],
                $dn,
                (string) ($row['theme'] ?? 'system'),
            );
        }

        return $users;
    }

    public function delete(string $userId): void
    {
        $this->connection->delete('users', ['id' => $userId]);
    }

    public function invalidateApiKeys(string $userId): int
    {
        return (int) $this->connection->executeStatement(
            'UPDATE api_keys SET is_active = 0 WHERE user_id = ? AND is_active = 1',
            [$userId],
        );
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Loads all roles (with permissions) assigned to a single user.
     *
     * @return list<Role>
     */
    private function loadRolesForUser(string $userId): array
    {
        $result = $this->loadRolesForUsers([$userId]);
        return $result[$userId] ?? [];
    }

    /**
     * Loads roles for multiple user IDs in bulk (2 queries total).
     *
     * @param list<string>              $userIds
     * @return array<string, list<Role>> keyed by user_id
     */
    private function loadRolesForUsers(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        // 1. Fetch all (user_id, role_id, role_name) tuples.
        $urRows = $this->connection->fetchAllAssociative(
            'SELECT ur.user_id, r.id AS role_id, r.name AS role_name
               FROM user_roles ur
               JOIN roles r ON r.id = ur.role_id
              WHERE ur.user_id IN (?)',
            [$userIds],
            [ArrayParameterType::STRING],
        );

        if ($urRows === []) {
            return [];
        }

        // Collect unique role IDs.
        $roleIds = array_values(array_unique(
            array_map(static fn(array $r): string => (string) ($r['role_id'] ?? ''), $urRows),
        ));

        // 2. Fetch all permissions for those roles.
        $permRows = $this->connection->fetchAllAssociative(
            'SELECT role_id, permission FROM role_permissions WHERE role_id IN (?)',
            [$roleIds],
            [ArrayParameterType::STRING],
        );

        // Group permissions by role_id.
        /** @var array<string, list<Permission>> $permsByRole */
        $permsByRole = [];
        foreach ($permRows as $pr) {
            $rid  = (string) ($pr['role_id'] ?? '');
            $perm = Permission::tryFrom((string) ($pr['permission'] ?? ''));
            if ($perm !== null) {
                $permsByRole[$rid][] = $perm;
            }
        }

        // Build Role objects, deduplicated.
        /** @var array<string, Role> $roleObjects */
        $roleObjects = [];
        foreach ($urRows as $row) {
            $rid = (string) ($row['role_id'] ?? '');
            if (!isset($roleObjects[$rid])) {
                $roleObjects[$rid] = new Role(
                    $rid,
                    (string) ($row['role_name'] ?? ''),
                    $permsByRole[$rid] ?? [],
                );
            }
        }

        // Map roles back to user IDs.
        /** @var array<string, list<Role>> $result */
        $result = [];
        foreach ($urRows as $row) {
            $uid = (string) ($row['user_id'] ?? '');
            $rid = (string) ($row['role_id'] ?? '');
            if (isset($roleObjects[$rid])) {
                $result[$uid][] = $roleObjects[$rid];
            }
        }

        return $result;
    }
}

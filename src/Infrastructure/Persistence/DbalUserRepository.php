<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Persistence;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use TowerDNS\Application\Repository\UserRepositoryInterface;
use TowerDNS\Application\Services\UserPreferences;
use TowerDNS\Domain\Account\TeamRole;
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
            'SELECT id, email, active, display_name, theme, language, locale, timezone, first_name, last_name, alternate_email, phone, mobile, street, street2, postal_code, city, region, country, external_reference, last_login_at, created_at, updated_at FROM users WHERE id = ? AND active = 1',
            [$id],
        );

        if (!is_array($raw)) {
            return null;
        }

        return $this->hydrate($raw, $this->loadRolesForUser((string) $raw['id']));
    }

    public function findByIdForAdministration(string $id): ?User
    {
        $raw = $this->connection->fetchAssociative(
            'SELECT id, email, active, display_name, theme, language, locale, timezone, first_name, last_name, alternate_email, phone, mobile, street, street2, postal_code, city, region, country, external_reference, last_login_at, created_at, updated_at FROM users WHERE id = ?',
            [$id],
        );
        return is_array($raw) ? $this->hydrate($raw, $this->loadRolesForUser((string) $raw['id'])) : null;
    }

    public function findByEmail(string $email): ?User
    {
        $raw = $this->connection->fetchAssociative(
            'SELECT id, email, active, display_name, theme, language, locale, timezone, first_name, last_name, alternate_email, phone, mobile, street, street2, postal_code, city, region, country, external_reference, last_login_at, created_at, updated_at FROM users WHERE email = ? AND active = 1',
            [$email],
        );

        if (!is_array($raw)) {
            return null;
        }

        return $this->hydrate($raw, $this->loadRolesForUser((string) $raw['id']));
    }

    public function fetchPasswordHash(string $email): ?string
    {
        $hash = $this->connection->fetchOne(
            'SELECT password_hash FROM users WHERE email = ? AND active = 1',
            [$email],
        );

        return is_string($hash) && $hash !== '' ? $hash : null;
    }

    public function fetchEncryptedTotpSecret(string $userId): ?string
    {
        $secret = $this->connection->fetchOne(
            'SELECT totp_secret_encrypted FROM users WHERE id = ?',
            [$userId],
        );

        return is_string($secret) && $secret !== '' ? $secret : null;
    }

    public function saveEncryptedTotpSecret(string $userId, ?string $ciphertext): void
    {
        $this->connection->update(
            'users',
            ['totp_secret_encrypted' => $ciphertext, 'updated_at' => $this->clock->now()->format('Y-m-d H:i:s')],
            ['id' => $userId],
        );
    }

    public function updateLastLoginAt(string $userId): void
    {
        $this->connection->update(
            'users',
            ['last_login_at' => $this->clock->now()->format('Y-m-d H:i:s')],
            ['id' => $userId],
        );
    }

    public function create(string $id, string $email, string $passwordHash): void
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $this->connection->insert('users', [
            'id'                    => $id,
            'email'                 => $email,
            'password_hash'         => $passwordHash,
            'totp_secret_encrypted' => null,
            'active'                => true,
            'theme'                 => 'system',
            'language'              => UserPreferences::DEFAULT_LANGUAGE,
            'locale'                => UserPreferences::DEFAULT_LOCALE,
            'timezone'              => UserPreferences::DEFAULT_TIMEZONE,
            'created_at'            => $now,
            'updated_at'            => $now,
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

    public function setActive(string $userId, bool $active): void
    {
        $this->connection->update('users', ['active' => $active, 'updated_at' => $this->clock->now()->format('Y-m-d H:i:s')], ['id' => $userId]);
    }

    public function updateProfile(string $userId, array $profile): void
    {
        $allowed = ['display_name', 'theme', 'language', 'locale', 'timezone', 'first_name', 'last_name', 'alternate_email', 'phone', 'mobile', 'street', 'street2', 'postal_code', 'city', 'region', 'country', 'external_reference'];
        $values  = array_intersect_key($profile, array_flip($allowed));
        foreach ($values as $key => $value) {
            $values[$key] = $value === '' ? null : $value;
        }
        $values['updated_at'] = $this->clock->now()->format('Y-m-d H:i:s');
        $this->connection->update('users', $values, ['id' => $userId]);
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

    public function findAll(?string $search = null, ?bool $active = true, int $limit = 100, int $offset = 0, ?string $accountSearch = null, ?TeamRole $membershipRole = null): array
    {
        $conditions = [];
        $params     = [];
        if ($active !== null) {
            $conditions[] = $active ? 'active = 1' : "(active = 0 OR active = '')";
        }
        if ($search !== null && trim($search) !== '') {
            $conditions[] = '(email LIKE ? OR display_name LIKE ? OR first_name LIKE ? OR last_name LIKE ?)';
            $term         = '%' . trim($search) . '%';
            $params[]     = $term;
            $params[]     = $term;
            $params[]     = $term;
            $params[]     = $term;
        }
        if ($accountSearch !== null && trim($accountSearch) !== '') {
            $conditions[] = 'EXISTS (SELECT 1 FROM account_memberships am JOIN accounts a ON a.id = am.account_id WHERE am.user_id = users.id AND (a.name LIKE ? OR a.slug LIKE ? OR a.customer_number LIKE ? OR a.external_reference LIKE ?))';
            $term         = '%' . trim($accountSearch) . '%';
            $params[]     = $term;
            $params[]     = $term;
            $params[]     = $term;
            $params[]     = $term;
        }
        if ($membershipRole instanceof TeamRole) {
            $conditions[] = 'EXISTS (SELECT 1 FROM account_memberships am WHERE am.user_id = users.id AND am.role = ?)';
            $params[]     = $membershipRole->value;
        }
        $where    = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
        $params[] = max(1, min($limit, 500));
        $params[] = max(0, $offset);
        $rows     = $this->connection->fetchAllAssociative(
            'SELECT id, email, active, display_name, theme, language, locale, timezone, first_name, last_name, alternate_email, phone, mobile, street, street2, postal_code, city, region, country, external_reference, last_login_at, created_at, updated_at FROM users' . $where . ' ORDER BY email LIMIT ? OFFSET ?',
            $params,
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
            $users[] = $this->hydrate($row, $rolesByUser[$uid] ?? []);
        }

        return $users;
    }

    public function delete(string $userId): void
    {
        $this->connection->delete('users', ['id' => $userId]);
    }

    public function countActiveUsersWithRole(string $roleId): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM users u JOIN user_roles ur ON ur.user_id = u.id WHERE u.active = 1 AND ur.role_id = ?', [$roleId]);
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
            'SELECT ur.user_id, r.id AS role_id, r.name AS role_name, r.is_system AS role_is_builtin
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
        /** @var array<string, list<string>> $permsByRole */
        $permsByRole = [];
        foreach ($permRows as $pr) {
            $rid                 = (string) ($pr['role_id'] ?? '');
            $permsByRole[$rid][] = (string) ($pr['permission'] ?? '');
        }

        // Build Role objects, deduplicated.
        /** @var array<string, Role> $roleObjects */
        $roleObjects = [];
        foreach ($urRows as $row) {
            $rid = (string) ($row['role_id'] ?? '');
            $roleObjects[$rid] ??= new Role(
                $rid,
                (string) ($row['role_name'] ?? ''),
                $permsByRole[$rid] ?? [],
                isBuiltIn: (bool) ($row['role_is_builtin'] ?? false),
            );
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

    /** @param array<string, mixed> $row
     *  @param list<Role> $roles
     */
    private function hydrate(array $row, array $roles): User
    {
        return new User(
            (string) $row['id'],
            (string) $row['email'],
            $roles,
            (bool) ($row['active'] ?? true),
            isset($row['display_name']) && $row['display_name'] !== '' ? (string) $row['display_name'] : null,
            (string) ($row['theme'] ?? 'system'),
            UserPreferences::normalizeLanguage((string) ($row['language'] ?? '')) ?? UserPreferences::DEFAULT_LANGUAGE,
            UserPreferences::normalizeLocale((string) ($row['locale'] ?? ''))     ?? UserPreferences::DEFAULT_LOCALE,
            UserPreferences::normalizeTimezone((string) ($row['timezone'] ?? '')) ?? UserPreferences::DEFAULT_TIMEZONE,
            $this->nullable($row, 'first_name'),
            $this->nullable($row, 'last_name'),
            $this->nullable($row, 'alternate_email'),
            $this->nullable($row, 'phone'),
            $this->nullable($row, 'mobile'),
            $this->nullable($row, 'street'),
            $this->nullable($row, 'street2'),
            $this->nullable($row, 'postal_code'),
            $this->nullable($row, 'city'),
            $this->nullable($row, 'region'),
            $this->nullable($row, 'country'),
            $this->nullable($row, 'external_reference'),
            isset($row['last_login_at']) ? (string) $row['last_login_at'] : null,
            isset($row['created_at']) ? (string) $row['created_at'] : null,
            isset($row['updated_at']) ? (string) $row['updated_at'] : null,
        );
    }

    /** @param array<string, mixed> $row */
    private function nullable(array $row, string $key): ?string
    {
        return isset($row[$key]) && $row[$key] !== '' ? (string) $row[$key] : null;
    }
}

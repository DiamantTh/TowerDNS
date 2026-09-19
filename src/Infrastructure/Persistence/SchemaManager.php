<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use TowerDNS\Application\Services\UserPreferences;
use TowerDNS\Domain\Account\AccountKind;
use TowerDNS\Domain\Account\PersonalAccount;
use TowerDNS\Domain\Auth\Permission;

/**
 * Manages the TowerDNS database schema using Doctrine DBAL's schema API.
 *
 * Call {@see createTablesIfNotExist()} explicitly during installation or a
 * controlled maintenance operation to ensure all required tables are present.
 * The method is idempotent for table creation — existing tables are never
 * dropped or altered.
 *
 * Call {@see seedSystemRoles()} after table creation to populate the built-in
 * roles defined in the RBAC documentation.  Existing rows are left untouched.
 *
 * Table creation order matters because of foreign-key constraints:
 *   roles → role_permissions → users → user_roles
 */
final readonly class SchemaManager
{
    public function __construct(private Connection $connection) {}

    /**
     * Creates every application table that does not yet exist during a
     * controlled installation operation. Existing tables are never altered.
     * Existing installations are upgraded through the versioned migration
     * runner, never as a side effect of a normal schema helper call.
     */
    public function createTablesIfNotExist(): void
    {
        $sm       = $this->connection->createSchemaManager();
        $existing = array_map(strtolower(...), $sm->listTableNames());

        foreach ($this->buildTables() as $table) {
            if (!in_array(strtolower($table->getName()), $existing, true)) {
                $sm->createTable($table);
            }
        }
    }

    /**
     * Merges the canonical schema into Doctrine's migration schema object.
     * Missing tables, columns, indexes and foreign keys are added only; no
     * existing definition is dropped or changed implicitly.
     */
    public function mergeCanonicalSchema(Schema $schema): void
    {
        foreach ($this->buildTables() as $expected) {
            if (!$schema->hasTable($expected->getName())) {
                $target = $schema->createTable($expected->getName());
                $this->copyTableDefinition($expected, $target);
                continue;
            }

            $target = $schema->getTable($expected->getName());
            foreach ($expected->getColumns() as $column) {
                if (!$target->hasColumn($column->getName())) {
                    $target->addColumn($column->getName(), $column->getType()->getName(), $this->columnOptions($column));
                }
            }

            foreach ($expected->getIndexes() as $index) {
                $indexPresent = false;
                foreach ($target->getIndexes() as $candidate) {
                    if ($index->isFulfilledBy($candidate)) {
                        $indexPresent = true;
                        break;
                    }
                }
                if ($indexPresent) {
                    continue;
                }
                if ($index->isPrimary()) {
                    $target->setPrimaryKey($index->getColumns(), $index->getName());
                } elseif ($index->isUnique()) {
                    $target->addUniqueIndex($index->getColumns(), $index->getName(), $index->getOptions());
                } else {
                    $target->addIndex($index->getColumns(), $index->getName(), $index->getFlags(), $index->getOptions());
                }
            }

            foreach ($expected->getForeignKeys() as $foreignKey) {
                foreach ($target->getForeignKeys() as $candidate) {
                    if ($this->foreignKeysMatch($foreignKey, $candidate)) {
                        continue 2;
                    }
                }
                $target->addForeignKeyConstraint(
                    $foreignKey->getForeignTableName(),
                    $foreignKey->getLocalColumns(),
                    $foreignKey->getForeignColumns(),
                    $foreignKey->getOptions(),
                    $foreignKey->getName(),
                );
            }
        }
    }

    /**
     * Returns additive schema problems that prevent a safe baseline. Type
     * mismatches are reported, while existing extra objects are tolerated.
     *
     * @return list<string>
     */
    public function schemaIssues(): array
    {
        $manager  = $this->connection->createSchemaManager();
        $existing = array_fill_keys(array_map(strtolower(...), $manager->listTableNames()), true);
        $issues   = [];

        foreach ($this->buildTables() as $expected) {
            $name = strtolower($expected->getName());
            if (!isset($existing[$name])) {
                $issues[] = sprintf('missing table %s', $expected->getName());
                continue;
            }

            $actual = $manager->introspectTable($expected->getName());
            foreach ($expected->getColumns() as $column) {
                if (!$actual->hasColumn($column->getName())) {
                    $issues[] = sprintf('missing column %s.%s', $expected->getName(), $column->getName());
                    continue;
                }
                if ($actual->getColumn($column->getName())->getType()->getName() !== $column->getType()->getName()) {
                    $issues[] = sprintf('incompatible type for %s.%s', $expected->getName(), $column->getName());
                }
            }

            foreach ($expected->getIndexes() as $index) {
                $matching = null;
                foreach ($actual->getIndexes() as $candidate) {
                    if ($index->isFulfilledBy($candidate)) {
                        $matching = $candidate;
                        break;
                    }
                }
                if ($matching === null) {
                    $issues[] = sprintf('missing index %s on %s', $index->getName(), $expected->getName());
                }
            }

            foreach ($expected->getForeignKeys() as $foreignKey) {
                $matching = false;
                foreach ($actual->getForeignKeys() as $candidate) {
                    if ($this->foreignKeysMatch($foreignKey, $candidate)) {
                        $matching = true;
                        break;
                    }
                }
                if (!$matching) {
                    $issues[] = sprintf('missing foreign key %s on %s', $foreignKey->getName(), $expected->getName());
                }
            }
        }

        return $issues;
    }

    private function foreignKeysMatch(ForeignKeyConstraint $expected, ForeignKeyConstraint $candidate): bool
    {
        return $candidate->getUnqualifiedForeignTableName() === $expected->getUnqualifiedForeignTableName()
            && $candidate->getLocalColumns()                === $expected->getLocalColumns()
            && $candidate->getForeignColumns()              === $expected->getForeignColumns();
    }

    public function schemaIsCurrent(): bool
    {
        return $this->schemaIssues() === [];
    }

    /**
     * Returns data-level prerequisites that must hold before an existing
     * database may be marked as the migration baseline.
     *
     * @return list<string>
     */
    public function dataIssues(): array
    {
        $manager = $this->connection->createSchemaManager();
        if (!$manager->tablesExist(['users', 'roles', 'role_permissions', 'accounts', 'account_memberships', 'account_resource_limits'])) {
            return [];
        }

        $issues        = [];
        $requiredRoles = ['viewer', 'editor', 'dnssec_op', 'provider_op', 'iam_admin', 'superadmin'];
        $existingRoles = array_map(
            static fn(mixed $role): string => (string) $role,
            $this->connection->fetchFirstColumn('SELECT id FROM roles'),
        );
        $missingRoles = array_values(array_diff($requiredRoles, $existingRoles));
        if ($missingRoles !== []) {
            $issues[] = 'missing system roles: ' . implode(', ', $missingRoles);
        }
        $requiredPermissions = array_map(
            static fn(Permission $permission): string => $permission->value,
            Permission::cases(),
        );
        $existingSuperadminPermissions = array_map(
            static fn(mixed $permission): string => (string) $permission,
            $this->connection->fetchFirstColumn("SELECT permission FROM role_permissions WHERE role_id = 'superadmin'"),
        );
        $missingSuperadminPermissions = array_values(array_diff($requiredPermissions, $existingSuperadminPermissions));
        if ($missingSuperadminPermissions !== []) {
            $issues[] = 'superadmin role is missing permissions';
        }

        $requiredSettings = [
            'security.password.min_length',
            'security.password.min_score',
            'security.password.hibp_enabled',
            'security.password.hibp_fail_open',
            'security.password.hibp_timeout',
        ];
        if ($manager->tablesExist(['system_settings'])) {
            $existingSettings = array_map(
                static fn(mixed $setting): string => (string) $setting,
                $this->connection->fetchFirstColumn('SELECT setting_key FROM system_settings'),
            );
            $missingSettings = array_values(array_diff($requiredSettings, $existingSettings));
            if ($missingSettings !== []) {
                $issues[] = 'missing system settings: ' . implode(', ', $missingSettings);
            }
        }

        if ((int) $this->connection->fetchOne('SELECT COUNT(*) FROM users') === 0) {
            $issues[] = 'no users found';
        }

        foreach ($this->connection->fetchFirstColumn('SELECT id FROM users') as $userId) {
            $personalCount = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM accounts WHERE account_type = ? AND personal_user_id = ?',
                [AccountKind::PERSONAL->value, (string) $userId],
            );
            if ($personalCount !== 1) {
                $issues[] = sprintf('user %s does not have exactly one personal account', (string) $userId);
            }
        }

        $missingLimits = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM accounts a LEFT JOIN account_resource_limits l ON l.account_id = a.id WHERE l.account_id IS NULL',
        );
        if ($missingLimits > 0) {
            $issues[] = sprintf('%d account(s) have no resource limits', $missingLimits);
        }

        $personalMembers = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM (SELECT a.id FROM accounts a JOIN account_memberships m ON m.account_id = a.id WHERE a.account_type = ? GROUP BY a.id HAVING COUNT(*) <> 1) invalid_personal_accounts',
            [AccountKind::PERSONAL->value],
        );
        if ($personalMembers > 0) {
            $issues[] = 'personal accounts must have exactly one owner membership';
        }

        $personalOwners = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM accounts a WHERE a.account_type = ? AND NOT EXISTS (SELECT 1 FROM account_memberships m WHERE m.account_id = a.id AND m.user_id = a.personal_user_id AND m.role = 'owner')",
            [AccountKind::PERSONAL->value],
        );
        if ($personalOwners > 0) {
            $issues[] = 'personal accounts must be owned by their personal user';
        }

        $accountOwners = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM accounts a WHERE NOT EXISTS (SELECT 1 FROM account_memberships m WHERE m.account_id = a.id AND m.user_id = a.owner_user_id AND m.role = 'owner')",
        );
        if ($accountOwners > 0) {
            $issues[] = 'accounts must have an owner membership';
        }

        return $issues;
    }

    /**
     * Returns true when all application tables are present.
     */
    public function schemaExists(): bool
    {
        $sm = $this->connection->createSchemaManager();
        return $sm->tablesExist(['users', 'roles', 'role_permissions', 'user_roles']);
    }

    /**
     * Inserts built-in, system-scoped roles when they are absent.
     *
     * Built-in roles: viewer, editor, dnssec_op, provider_op, iam_admin, superadmin.
     * Rows that already exist are left untouched.
     */
    public function seedSystemRoles(): void
    {
        $now = new \DateTimeImmutable()->format('Y-m-d H:i:s');

        /** @var array<string, array{name: string, permissions: list<Permission>}> $definitions */
        $definitions = [
            'viewer' => [
                'name'        => 'Viewer',
                'permissions' => [
                    Permission::ZONE_LIST,
                    Permission::ZONE_READ,
                    Permission::RECORD_READ,
                    Permission::DNSSEC_STATUS_READ,
                ],
            ],
            'editor' => [
                'name'        => 'Editor',
                'permissions' => [
                    Permission::ZONE_LIST,
                    Permission::ZONE_READ,
                    Permission::ZONE_CREATE,
                    Permission::ZONE_UPDATE,
                    Permission::RECORD_READ,
                    Permission::RECORD_CREATE,
                    Permission::RECORD_UPDATE,
                    Permission::RECORD_DELETE,
                    Permission::DNSSEC_STATUS_READ,
                ],
            ],
            'dnssec_op' => [
                'name'        => 'DNSSEC Operator',
                'permissions' => [
                    Permission::ZONE_LIST,
                    Permission::ZONE_READ,
                    Permission::RECORD_READ,
                    Permission::DNSSEC_STATUS_READ,
                    Permission::DNSSEC_ACTION_EXECUTE,
                ],
            ],
            'provider_op' => [
                'name'        => 'Provider Operator',
                'permissions' => [
                    Permission::PROVIDER_CREDENTIALS_MANAGE,
                    Permission::PROVIDER_CONFIG_MANAGE,
                ],
            ],
            'iam_admin' => [
                'name'        => 'IAM Administrator',
                'permissions' => [
                    Permission::USER_MANAGE,
                    Permission::ROLE_MANAGE,
                ],
            ],
            'superadmin' => [
                'name' => 'Super Administrator',
                // Dynamic module permissions are derived at authorization time
                // from PermissionRegistry. Persist only the Core baseline so
                // seed runs never erase explicit, stale module grants.
                'permissions' => Permission::cases(),
            ],
        ];

        foreach ($definitions as $id => $def) {
            $exists = $this->connection->fetchOne(
                'SELECT id FROM roles WHERE id = ?',
                [$id],
            );

            if ($exists !== false) {
                if ($id === 'superadmin') {
                    $this->ensureRolePermissions($id, $def['permissions']);
                }
                continue;
            }

            $this->connection->insert('roles', [
                'id'         => $id,
                'name'       => $def['name'],
                'is_system'  => true,
                'created_at' => $now,
            ]);

            foreach ($def['permissions'] as $permission) {
                $this->connection->insert('role_permissions', [
                    'role_id'    => $id,
                    'permission' => $permission->value,
                ]);
            }
        }
    }

    /** @param list<Permission> $permissions */
    private function ensureRolePermissions(string $roleId, array $permissions): void
    {
        foreach ($permissions as $permission) {
            $exists = $this->connection->fetchOne(
                'SELECT 1 FROM role_permissions WHERE role_id = ? AND permission = ?',
                [$roleId, $permission->value],
            );

            if ($exists === false) {
                $this->connection->insert('role_permissions', [
                    'role_id'    => $roleId,
                    'permission' => $permission->value,
                ]);
            }
        }
    }

    /**
     * Seeds the first superadmin user.  Only inserts when the users table is
     * empty — safe to call as part of the web installer flow.
     *
     * $passwordHash MUST already be the output of {@see password_hash()}.
     */
    public function seedFirstUser(string $id, string $email, string $passwordHash, string $displayName = ''): void
    {
        $count = $this->connection->fetchOne('SELECT COUNT(*) FROM users');
        if ($count !== false && (int) $count > 0) {
            return;
        }

        $now = new \DateTimeImmutable()->format('Y-m-d H:i:s');
        $this->connection->transactional(function () use ($id, $email, $passwordHash, $displayName, $now): void {
            $this->connection->insert('users', [
                'id'                    => $id,
                'email'                 => $email,
                'display_name'          => $displayName !== '' ? $displayName : null,
                'password_hash'         => $passwordHash,
                'totp_secret_encrypted' => null,
                'active'                => true,
                'theme'                 => 'system',
                'language'              => UserPreferences::DEFAULT_LANGUAGE,
                'locale'                => UserPreferences::DEFAULT_LOCALE,
                'timezone'              => UserPreferences::DEFAULT_TIMEZONE,
                'last_login_at'         => null,
                'created_at'            => $now,
                'updated_at'            => $now,
            ]);

            $this->connection->insert('user_roles', [
                'user_id' => $id,
                'role_id' => 'superadmin',
            ]);

            $this->connection->insert('accounts', [
                'name'             => 'Personal',
                'slug'             => PersonalAccount::slugFor($id),
                'owner_user_id'    => $id,
                'account_type'     => AccountKind::PERSONAL->value,
                'personal_user_id' => $id,
                'is_active'        => true,
                'created_at'       => $now,
            ]);
            $accountId = (int) $this->connection->lastInsertId();
            $this->connection->insert('account_memberships', ['account_id' => $accountId, 'user_id' => $id, 'role' => 'owner', 'invited_by' => null, 'created_at' => $now]);
            $this->connection->insert('account_resource_limits', ['account_id' => $accountId, 'max_zones' => null, 'max_members' => null, 'max_provider_accounts' => null]);
        });
    }

    /**
     * Seeds a default Account and owner membership unless at least one account already exists.
     *
     * @param non-empty-string $ownerUserId GUID of the admin user
     * @param non-empty-string $accountName Display name for the account
     * @param non-empty-string $slug        URL-safe slug (a-z0-9-, 2–64 chars)
     * @param non-empty-string $now         Formatted datetime string (Y-m-d H:i:s)
     */
    public function seedDefaultAccount(
        string $ownerUserId,
        string $accountName,
        string $slug,
        string $now,
    ): void {
        $count = $this->connection->fetchOne('SELECT COUNT(*) FROM accounts WHERE account_type = ?', [AccountKind::ORGANIZATION->value]);
        if ($count !== false && (int) $count > 0) {
            return;
        }

        $this->connection->transactional(function () use ($ownerUserId, $accountName, $slug, $now): void {
            $this->connection->insert('accounts', [
                'name'          => $accountName,
                'slug'          => $slug,
                'owner_user_id' => $ownerUserId,
                'account_type'  => AccountKind::ORGANIZATION->value,
                'is_active'     => true,
                'created_at'    => $now,
            ]);

            $accountId = (int) $this->connection->lastInsertId();
            $this->connection->insert('account_memberships', [
                'account_id' => $accountId,
                'user_id'    => $ownerUserId,
                'role'       => 'owner',
                'invited_by' => null,
                'created_at' => $now,
            ]);
            $this->connection->insert('account_resource_limits', [
                'account_id'            => $accountId,
                'max_zones'             => null,
                'max_members'           => null,
                'max_provider_accounts' => null,
            ]);
        });
    }

    /**
     * Seeds runtime-configurable system settings with sensible defaults.
     *
     * Idempotent — existing keys are left untouched. Bootstrap config
     * (DB connection, encryption key, app hostname, …) stays in TOML and
     * is NOT touched by this method.
     */
    public function seedSystemSettingsDefaults(): void
    {
        $now = new \DateTimeImmutable()->format('Y-m-d H:i:s');

        $defaults = [
            'security.password.min_length'     => 16,
            'security.password.min_score'      => 2,
            'security.password.hibp_enabled'   => false,
            'security.password.hibp_fail_open' => true,
            'security.password.hibp_timeout'   => 3.0,
        ];

        foreach ($defaults as $key => $value) {
            $exists = $this->connection->fetchOne(
                'SELECT setting_key FROM system_settings WHERE setting_key = ?',
                [$key],
            );
            if ($exists !== false) {
                continue;
            }

            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                continue;
            }

            $this->connection->insert('system_settings', [
                'setting_key'   => $key,
                'setting_value' => $encoded,
                'updated_at'    => $now,
                'updated_by'    => null,
            ]);
        }
    }

    /**
     * Builds the canonical set of DBAL Table objects in dependency order.
     *
     * @return list<Table>
     */
    private function buildTables(): array
    {
        // roles ---------------------------------------------------------------
        $roles = new Table('roles');
        $roles->addColumn('id', Types::STRING, ['length' => 64]);
        $roles->addColumn('name', Types::STRING, ['length' => 255]);
        $roles->addColumn('is_system', Types::BOOLEAN, ['default' => false]);
        $roles->addColumn('created_at', Types::DATETIME_MUTABLE);
        $roles->setPrimaryKey(['id']);

        // role_permissions ----------------------------------------------------
        $rolePerms = new Table('role_permissions');
        $rolePerms->addColumn('role_id', Types::STRING, ['length' => 64]);
        $rolePerms->addColumn('permission', Types::STRING, ['length' => 64]);
        $rolePerms->setPrimaryKey(['role_id', 'permission']);
        $rolePerms->addForeignKeyConstraint(
            'roles',
            ['role_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
            'fk_rp_role_id',
        );

        // users ---------------------------------------------------------------
        $users = new Table('users');
        $users->addColumn('id', Types::GUID);
        $users->addColumn('email', Types::STRING, ['length' => 254]);
        $users->addColumn('display_name', Types::STRING, ['length' => 64, 'notnull' => false]);
        $users->addColumn('password_hash', Types::STRING, ['length' => 255]);
        $users->addColumn('totp_secret_encrypted', Types::STRING, ['length' => 255, 'notnull' => false]);
        $users->addColumn('active', Types::BOOLEAN, ['default' => true]);
        $users->addColumn('theme', Types::STRING, ['length' => 64, 'default' => 'system']);
        $users->addColumn('language', Types::STRING, ['length' => 16, 'default' => UserPreferences::DEFAULT_LANGUAGE]);
        $users->addColumn('locale', Types::STRING, ['length' => 16, 'default' => UserPreferences::DEFAULT_LOCALE]);
        $users->addColumn('timezone', Types::STRING, ['length' => 64, 'default' => UserPreferences::DEFAULT_TIMEZONE]);
        $users->addColumn('first_name', Types::STRING, ['length' => 100, 'notnull' => false]);
        $users->addColumn('last_name', Types::STRING, ['length' => 100, 'notnull' => false]);
        $users->addColumn('alternate_email', Types::STRING, ['length' => 254, 'notnull' => false]);
        $users->addColumn('phone', Types::STRING, ['length' => 64, 'notnull' => false]);
        $users->addColumn('mobile', Types::STRING, ['length' => 64, 'notnull' => false]);
        $users->addColumn('street', Types::STRING, ['length' => 255, 'notnull' => false]);
        $users->addColumn('street2', Types::STRING, ['length' => 255, 'notnull' => false]);
        $users->addColumn('postal_code', Types::STRING, ['length' => 32, 'notnull' => false]);
        $users->addColumn('city', Types::STRING, ['length' => 128, 'notnull' => false]);
        $users->addColumn('region', Types::STRING, ['length' => 128, 'notnull' => false]);
        $users->addColumn('country', Types::STRING, ['length' => 2, 'notnull' => false]);
        $users->addColumn('external_reference', Types::STRING, ['length' => 255, 'notnull' => false]);
        $users->addColumn('last_login_at', Types::DATETIME_MUTABLE, ['notnull' => false]);
        $users->addColumn('created_at', Types::DATETIME_MUTABLE);
        $users->addColumn('updated_at', Types::DATETIME_MUTABLE);
        $users->setPrimaryKey(['id']);
        $users->addUniqueIndex(['email'], 'uq_users_email');

        // user_roles ----------------------------------------------------------
        $userRoles = new Table('user_roles');
        $userRoles->addColumn('user_id', Types::GUID);
        $userRoles->addColumn('role_id', Types::STRING, ['length' => 64]);
        $userRoles->setPrimaryKey(['user_id', 'role_id']);
        $userRoles->addForeignKeyConstraint(
            'users',
            ['user_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
            'fk_ur_user_id',
        );
        $userRoles->addForeignKeyConstraint(
            'roles',
            ['role_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
            'fk_ur_role_id',
        );

        // webauthn_credentials -----------------------------------------------
        $waCredentials = new Table('webauthn_credentials');
        $waCredentials->addColumn('credential_id', Types::TEXT);
        $waCredentials->addColumn('user_id', Types::GUID);
        $waCredentials->addColumn('name', Types::STRING, ['length' => 64]);
        $waCredentials->addColumn('data', Types::TEXT);
        $waCredentials->addColumn('created_at', Types::DATETIME_MUTABLE);
        $waCredentials->addColumn('last_used_at', Types::DATETIME_MUTABLE, ['notnull' => false]);
        $waCredentials->setPrimaryKey(['credential_id']);
        $waCredentials->addIndex(['user_id'], 'idx_wac_user_id');
        $waCredentials->addForeignKeyConstraint(
            'users',
            ['user_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
            'fk_wac_user_id',
        );

        // api_keys -----------------------------------------------------------
        $apiKeys = new Table('api_keys');
        $apiKeys->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $apiKeys->addColumn('user_id', Types::GUID);
        $apiKeys->addColumn('name', Types::STRING, ['length' => 255]);
        $apiKeys->addColumn('api_key', Types::STRING, ['length' => 255]);
        $apiKeys->addColumn('created_at', Types::DATETIME_MUTABLE);
        $apiKeys->addColumn('last_used', Types::DATETIME_MUTABLE, ['notnull' => false]);
        $apiKeys->addColumn('is_active', Types::BOOLEAN, ['default' => true]);
        $apiKeys->setPrimaryKey(['id']);
        $apiKeys->addUniqueIndex(['api_key'], 'uq_api_keys_hash');
        $apiKeys->addIndex(['user_id'], 'idx_ak_user_id');
        $apiKeys->addForeignKeyConstraint(
            'users',
            ['user_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
            'fk_ak_user_id',
        );

        // accounts -----------------------------------------------------------
        $accounts = new Table('accounts');
        $accounts->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $accounts->addColumn('name', Types::STRING, ['length' => 255]);
        $accounts->addColumn('slug', Types::STRING, ['length' => 100]);
        $accounts->addColumn('owner_user_id', Types::GUID);
        $accounts->addColumn('account_type', Types::STRING, ['length' => 32, 'default' => AccountKind::ORGANIZATION->value]);
        $accounts->addColumn('personal_user_id', Types::GUID, ['notnull' => false]);
        $accounts->addColumn('customer_number', Types::STRING, ['length' => 64, 'notnull' => false]);
        $accounts->addColumn('external_reference', Types::STRING, ['length' => 255, 'notnull' => false]);
        $accounts->addColumn('is_active', Types::BOOLEAN, ['default' => true]);
        $accounts->addColumn('created_at', Types::DATETIME_MUTABLE);
        $accounts->setPrimaryKey(['id']);
        $accounts->addUniqueIndex(['slug'], 'uq_accounts_slug');
        $accounts->addIndex(['owner_user_id'], 'idx_accounts_owner');
        $accounts->addUniqueIndex(['personal_user_id'], 'uq_accounts_personal_user');
        $accounts->addForeignKeyConstraint(
            'users',
            ['owner_user_id'],
            ['id'],
            ['onDelete' => 'RESTRICT'],
            'fk_acc_owner_user_id',
        );
        $accounts->addForeignKeyConstraint(
            'users',
            ['personal_user_id'],
            ['id'],
            ['onDelete' => 'RESTRICT'],
            'fk_acc_personal_user_id',
        );

        // account_resource_limits -------------------------------------------
        $resourceLimits = new Table('account_resource_limits');
        $resourceLimits->addColumn('account_id', Types::INTEGER);
        $resourceLimits->addColumn('max_zones', Types::INTEGER, ['notnull' => false]);
        $resourceLimits->addColumn('max_members', Types::INTEGER, ['notnull' => false]);
        $resourceLimits->addColumn('max_provider_accounts', Types::INTEGER, ['notnull' => false]);
        $resourceLimits->setPrimaryKey(['account_id']);
        $resourceLimits->addForeignKeyConstraint('accounts', ['account_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_arl_account_id');

        // account_memberships ------------------------------------------------
        $accMembers = new Table('account_memberships');
        $accMembers->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $accMembers->addColumn('account_id', Types::INTEGER);
        $accMembers->addColumn('user_id', Types::GUID);
        $accMembers->addColumn('role', Types::STRING, ['length' => 32]);
        $accMembers->addColumn('invited_by', Types::GUID, ['notnull' => false]);
        $accMembers->addColumn('created_at', Types::DATETIME_MUTABLE);
        $accMembers->setPrimaryKey(['id']);
        $accMembers->addUniqueIndex(['account_id', 'user_id'], 'uq_accm_account_user');
        $accMembers->addForeignKeyConstraint(
            'accounts',
            ['account_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
            'fk_accm_account_id',
        );
        $accMembers->addForeignKeyConstraint(
            'users',
            ['user_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
            'fk_accm_user_id',
        );
        $accMembers->addIndex(['user_id'], 'idx_accm_user_id');
        $accMembers->addForeignKeyConstraint(
            'users',
            ['invited_by'],
            ['id'],
            ['onDelete' => 'SET NULL'],
            'fk_accm_invited_by',
        );

        // account_invitations -----------------------------------------------
        // Raw invitation tokens are never persisted; token_hash is SHA-256.
        $accountInvitations = new Table('account_invitations');
        $accountInvitations->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $accountInvitations->addColumn('account_id', Types::INTEGER);
        $accountInvitations->addColumn('email', Types::STRING, ['length' => 254]);
        $accountInvitations->addColumn('user_id', Types::GUID, ['notnull' => false]);
        $accountInvitations->addColumn('role', Types::STRING, ['length' => 32]);
        $accountInvitations->addColumn('invited_by', Types::GUID);
        $accountInvitations->addColumn('token_hash', Types::STRING, ['length' => 64]);
        $accountInvitations->addColumn('created_at', Types::STRING, ['length' => 19]);
        $accountInvitations->addColumn('expires_at', Types::STRING, ['length' => 19]);
        $accountInvitations->addColumn('accepted_at', Types::STRING, ['length' => 19, 'notnull' => false]);
        $accountInvitations->addColumn('declined_at', Types::STRING, ['length' => 19, 'notnull' => false]);
        $accountInvitations->addColumn('revoked_at', Types::STRING, ['length' => 19, 'notnull' => false]);
        $accountInvitations->setPrimaryKey(['id']);
        $accountInvitations->addUniqueIndex(['token_hash'], 'uq_account_invitation_token');
        $accountInvitations->addIndex(['account_id', 'email'], 'idx_account_invitation_account_email');
        $accountInvitations->addIndex(['email', 'expires_at'], 'idx_account_invitation_email_expiry');
        $accountInvitations->addForeignKeyConstraint('accounts', ['account_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_ai_account_id');
        $accountInvitations->addForeignKeyConstraint('users', ['user_id'], ['id'], ['onDelete' => 'SET NULL'], 'fk_ai_user_id');
        $accountInvitations->addForeignKeyConstraint('users', ['invited_by'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_ai_invited_by');

        // provider_accounts --------------------------------------------------
        $provAccounts = new Table('provider_accounts');
        $provAccounts->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $provAccounts->addColumn('account_id', Types::INTEGER);
        $provAccounts->addColumn('provider_type', Types::STRING, ['length' => 64]);
        $provAccounts->addColumn('name', Types::STRING, ['length' => 255]);
        $provAccounts->addColumn('credentials_encrypted', Types::TEXT);
        $provAccounts->addColumn('credentials_version', Types::INTEGER, ['default' => 1]);
        $provAccounts->addColumn('is_active', Types::BOOLEAN, ['default' => true]);
        $provAccounts->addColumn('created_at', Types::DATETIME_MUTABLE);
        $provAccounts->addColumn('last_tested_at', Types::DATETIME_MUTABLE, ['notnull' => false]);
        $provAccounts->addColumn('last_used_at', Types::DATETIME_MUTABLE, ['notnull' => false]);
        $provAccounts->setPrimaryKey(['id']);
        $provAccounts->addIndex(['account_id'], 'idx_pa_account_id');
        $provAccounts->addForeignKeyConstraint(
            'accounts',
            ['account_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
            'fk_pa_account_id',
        );
        $provAccounts->addUniqueIndex(['id', 'account_id'], 'uq_provider_accounts_id_account');

        // managed_zones ------------------------------------------------------
        $managedZones = new Table('managed_zones');
        $managedZones->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $managedZones->addColumn('account_id', Types::INTEGER);
        $managedZones->addColumn('provider_account_id', Types::INTEGER);
        $managedZones->addColumn('provider_zone_id', Types::STRING, ['length' => 255]);
        $managedZones->addColumn('canonical_name', Types::STRING, ['length' => 253]);
        $managedZones->addColumn('created_at', Types::DATETIME_MUTABLE);
        $managedZones->setPrimaryKey(['id']);
        $managedZones->addUniqueIndex(['provider_account_id', 'provider_zone_id'], 'uq_managed_zone_provider_zone');
        $managedZones->addIndex(['account_id'], 'idx_managed_zones_account');
        $managedZones->addForeignKeyConstraint('accounts', ['account_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_mz_account_id');
        $managedZones->addForeignKeyConstraint('provider_accounts', ['provider_account_id', 'account_id'], ['id', 'account_id'], ['onDelete' => 'CASCADE'], 'fk_mz_provider_account');

        // zone_memberships ---------------------------------------------------
        $zoneMembers = new Table('zone_memberships');
        $zoneMembers->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $zoneMembers->addColumn('managed_zone_id', Types::INTEGER);
        $zoneMembers->addColumn('user_id', Types::GUID);
        $zoneMembers->addColumn('role', Types::STRING, ['length' => 32]);
        $zoneMembers->addColumn('granted_by', Types::GUID, ['notnull' => false]);
        $zoneMembers->addColumn('created_at', Types::DATETIME_MUTABLE);
        $zoneMembers->setPrimaryKey(['id']);
        $zoneMembers->addUniqueIndex(['managed_zone_id', 'user_id'], 'uq_zm_zone_user');
        $zoneMembers->addForeignKeyConstraint(
            'managed_zones',
            ['managed_zone_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
            'fk_zm_managed_zone_id',
        );
        $zoneMembers->addForeignKeyConstraint(
            'users',
            ['user_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
            'fk_zm_user_id',
        );
        $zoneMembers->addIndex(['user_id'], 'idx_zm_user_id');
        $zoneMembers->addForeignKeyConstraint(
            'users',
            ['granted_by'],
            ['id'],
            ['onDelete' => 'SET NULL'],
            'fk_zm_granted_by',
        );

        // admin_impersonation_sessions ---------------------------------------
        $impSessions = new Table('admin_impersonation_sessions');
        $impSessions->addColumn('id', Types::GUID);
        $impSessions->addColumn('actor_user_id', Types::GUID);
        $impSessions->addColumn('effective_user_id', Types::GUID, ['notnull' => false]);
        $impSessions->addColumn('effective_account_id', Types::INTEGER, ['notnull' => false]);
        $impSessions->addColumn('reason', Types::TEXT);
        $impSessions->addColumn('created_at', Types::DATETIME_MUTABLE);
        $impSessions->addColumn('expires_at', Types::DATETIME_MUTABLE);
        $impSessions->addColumn('ended_at', Types::DATETIME_MUTABLE, ['notnull' => false]);
        $impSessions->setPrimaryKey(['id']);
        $impSessions->addIndex(['actor_user_id'], 'idx_ais_actor');
        $impSessions->addForeignKeyConstraint(
            'users',
            ['actor_user_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
            'fk_ais_actor_user_id',
        );
        $impSessions->addForeignKeyConstraint(
            'users',
            ['effective_user_id'],
            ['id'],
            ['onDelete' => 'SET NULL'],
            'fk_ais_effective_user_id',
        );
        $impSessions->addForeignKeyConstraint(
            'accounts',
            ['effective_account_id'],
            ['id'],
            ['onDelete' => 'SET NULL'],
            'fk_ais_effective_account_id',
        );
        $impSessions->addIndex(['effective_user_id'], 'idx_ais_effective_user');
        $impSessions->addIndex(['effective_account_id'], 'idx_ais_effective_account');

        // audit_logs ---------------------------------------------------------
        $auditLogs = new Table('audit_logs');
        $auditLogs->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $auditLogs->addColumn('actor_user_id', Types::GUID, ['notnull' => false]);
        $auditLogs->addColumn('effective_user_id', Types::GUID, ['notnull' => false]);
        $auditLogs->addColumn('account_id', Types::INTEGER, ['notnull' => false]);
        $auditLogs->addColumn('zone_id', Types::STRING, ['length' => 253, 'notnull' => false]);
        $auditLogs->addColumn('provider_account_id', Types::INTEGER, ['notnull' => false]);
        $auditLogs->addColumn('impersonation_session_id', Types::GUID, ['notnull' => false]);
        $auditLogs->addColumn('action', Types::STRING, ['length' => 128]);
        $auditLogs->addColumn('target_type', Types::STRING, ['length' => 64]);
        $auditLogs->addColumn('target_id', Types::STRING, ['length' => 255, 'notnull' => false]);
        $auditLogs->addColumn('before_json', Types::TEXT, ['notnull' => false]);
        $auditLogs->addColumn('after_json', Types::TEXT, ['notnull' => false]);
        $auditLogs->addColumn('metadata_json', Types::TEXT, ['notnull' => false]);
        $auditLogs->addColumn('ip_address', Types::STRING, ['length' => 45, 'notnull' => false]);
        $auditLogs->addColumn('user_agent', Types::TEXT, ['notnull' => false]);
        $auditLogs->addColumn('created_at', Types::DATETIME_MUTABLE);
        $auditLogs->setPrimaryKey(['id']);
        $auditLogs->addIndex(['account_id'], 'idx_al_account_id');
        $auditLogs->addIndex(['zone_id'], 'idx_al_zone_id');
        $auditLogs->addIndex(['actor_user_id'], 'idx_al_actor');
        $auditLogs->addIndex(['created_at'], 'idx_al_created_at');

        // password_reset_tokens ----------------------------------------------
        $pwResetTokens = new Table('password_reset_tokens');
        $pwResetTokens->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $pwResetTokens->addColumn('user_id', Types::GUID);
        $pwResetTokens->addColumn('token_hash', Types::STRING, ['length' => 64]);
        $pwResetTokens->addColumn('created_at', Types::STRING, ['length' => 19]);
        $pwResetTokens->addColumn('expires_at', Types::STRING, ['length' => 19]);
        $pwResetTokens->addColumn('used_at', Types::STRING, ['length' => 19, 'notnull' => false]);
        $pwResetTokens->addColumn('method', Types::STRING, ['length' => 32, 'default' => 'email_link']);
        $pwResetTokens->setPrimaryKey(['id']);
        $pwResetTokens->addUniqueIndex(['token_hash'], 'uq_prt_token_hash');
        $pwResetTokens->addIndex(['user_id'], 'idx_prt_user_id');
        $pwResetTokens->addIndex(['expires_at'], 'idx_prt_expires_at');
        $pwResetTokens->addForeignKeyConstraint(
            'users',
            ['user_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
            'fk_prt_user_id',
        );

        // system_settings ----------------------------------------------------
        // Runtime-konfigurierbare Werte (UI-editierbar). Bootstrap-Werte
        // (DB-Connection, encryption_key, app.hostname) bleiben in TOML.
        $systemSettings = new Table('system_settings');
        $systemSettings->addColumn('setting_key', Types::STRING, ['length' => 120]);
        $systemSettings->addColumn('setting_value', Types::TEXT);
        $systemSettings->addColumn('updated_at', Types::DATETIME_MUTABLE);
        $systemSettings->addColumn('updated_by', Types::GUID, ['notnull' => false]);
        $systemSettings->setPrimaryKey(['setting_key']);
        $systemSettings->addForeignKeyConstraint(
            'users',
            ['updated_by'],
            ['id'],
            ['onDelete' => 'SET NULL'],
            'fk_ss_updated_by',
        );

        return [
            $roles, $rolePerms, $users, $userRoles, $waCredentials, $apiKeys,
            $accounts, $resourceLimits, $accMembers, $accountInvitations, $provAccounts, $managedZones, $zoneMembers, $impSessions, $auditLogs,
            $pwResetTokens, $systemSettings,
        ];
    }

    /**
     * Backfills data introduced with the explicit account type and profile
     * model. Schema changes themselves are handled by versioned migrations.
     */
    public function backfillLegacyProfileAndAccountData(): void
    {
        $this->connection->executeStatement("UPDATE users SET language = locale WHERE language IS NULL OR language = ''");
        foreach ($this->connection->fetchAllAssociative('SELECT id, slug, owner_user_id FROM accounts') as $account) {
            if ((string) $account['slug'] === PersonalAccount::slugFor((string) $account['owner_user_id'])) {
                $this->connection->update('accounts', ['account_type' => AccountKind::PERSONAL->value, 'personal_user_id' => $account['owner_user_id']], ['id' => $account['id']]);
            }
        }
        $now = new \DateTimeImmutable()->format('Y-m-d H:i:s');
        foreach ($this->connection->fetchFirstColumn('SELECT id FROM users') as $userId) {
            $userId     = (string) $userId;
            $personalId = $this->connection->fetchOne('SELECT id FROM accounts WHERE account_type = ? AND personal_user_id = ?', [AccountKind::PERSONAL->value, $userId]);
            if ($personalId === false) {
                $slug = PersonalAccount::slugFor($userId);
                if ($this->connection->fetchOne('SELECT id FROM accounts WHERE slug = ?', [$slug]) !== false) {
                    throw new \RuntimeException("Cannot create the required personal account for user {$userId}: reserved slug is in use.");
                }
                $this->connection->insert('accounts', ['name' => 'Personal', 'slug' => $slug, 'owner_user_id' => $userId, 'account_type' => AccountKind::PERSONAL->value, 'personal_user_id' => $userId, 'is_active' => true, 'created_at' => $now]);
                $personalId = (int) $this->connection->lastInsertId();
            }
            if ($this->connection->fetchOne('SELECT 1 FROM account_memberships WHERE account_id = ? AND user_id = ?', [$personalId, $userId]) === false) {
                $this->connection->insert('account_memberships', ['account_id' => $personalId, 'user_id' => $userId, 'role' => 'owner', 'invited_by' => null, 'created_at' => $now]);
            }
            if ($this->connection->fetchOne('SELECT 1 FROM account_resource_limits WHERE account_id = ?', [$personalId]) === false) {
                $this->connection->insert('account_resource_limits', ['account_id' => $personalId, 'max_zones' => null, 'max_members' => null, 'max_provider_accounts' => null]);
            }
        }
    }

    private function copyTableDefinition(Table $source, Table $target): void
    {
        foreach ($source->getColumns() as $column) {
            $target->addColumn($column->getName(), $column->getType()->getName(), $this->columnOptions($column));
        }

        $primary = $source->getPrimaryKey();
        if ($primary !== null) {
            $target->setPrimaryKey($primary->getColumns(), $primary->getName());
        }
        foreach ($source->getIndexes() as $index) {
            if ($index->isPrimary()) {
                continue;
            }
            if ($index->isUnique()) {
                $target->addUniqueIndex($index->getColumns(), $index->getName(), $index->getOptions());
            } else {
                $target->addIndex($index->getColumns(), $index->getName(), $index->getFlags(), $index->getOptions());
            }
        }
        foreach ($source->getForeignKeys() as $foreignKey) {
            $target->addForeignKeyConstraint(
                $foreignKey->getForeignTableName(),
                $foreignKey->getLocalColumns(),
                $foreignKey->getForeignColumns(),
                $foreignKey->getOptions(),
                $foreignKey->getName(),
            );
        }
    }

    /** @return array<string, mixed> */
    private function columnOptions(Column $column): array
    {
        $options = [
            'notnull'       => $column->getNotnull(),
            'autoincrement' => $column->getAutoincrement(),
        ];
        foreach (['length', 'precision', 'scale', 'unsigned', 'fixed', 'default'] as $option) {
            $value = match ($option) {
                'length'    => $column->getLength(),
                'precision' => $column->getPrecision(),
                'scale'     => $column->getScale(),
                'unsigned'  => $column->getUnsigned(),
                'fixed'     => $column->getFixed(),
                'default'   => $column->getDefault(),
            };
            if ($value !== null) {
                $options[$option] = $value;
            }
        }

        return $options;
    }
}

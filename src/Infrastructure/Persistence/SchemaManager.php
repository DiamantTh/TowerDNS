<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use TowerDNS\Domain\Auth\Permission;

/**
 * Manages the TowerDNS database schema using Doctrine DBAL's schema API.
 *
 * Call {@see createTablesIfNotExist()} once during installation or on first
 * boot to ensure all required tables are present.  The method is idempotent —
 * existing tables are never dropped or altered.
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
     * Creates every application table that does not yet exist.
     * Safe to call on every boot — existing tables are never touched.
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
     * Returns true when all application tables are present.
     */
    public function schemaExists(): bool
    {
        $sm = $this->connection->createSchemaManager();
        return $sm->tablesExist(['users', 'roles', 'role_permissions', 'user_roles']);
    }

    /**
     * Inserts built-in system roles when they are absent.
     *
     * System roles: viewer, editor, dnssec_op, provider_op, iam_admin, superadmin.
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
                'name'        => 'Super Administrator',
                'permissions' => Permission::cases(),
            ],
        ];

        foreach ($definitions as $id => $def) {
            $exists = $this->connection->fetchOne(
                'SELECT id FROM roles WHERE id = ?',
                [$id],
            );

            if ($exists !== false) {
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

        $this->connection->insert('users', [
            'id'            => $id,
            'email'         => $email,
            'display_name'  => $displayName !== '' ? $displayName : null,
            'password_hash' => $passwordHash,
            'totp_secret'   => null,
            'active'        => true,
            'theme'         => 'system',
            'locale'        => 'en',
            'last_login_at' => null,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);

        $this->connection->insert('user_roles', [
            'user_id' => $id,
            'role_id' => 'superadmin',
        ]);
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
        $count = $this->connection->fetchOne('SELECT COUNT(*) FROM accounts');
        if ($count !== false && (int) $count > 0) {
            return;
        }

        $this->connection->insert('accounts', [
            'name'          => $accountName,
            'slug'          => $slug,
            'owner_user_id' => $ownerUserId,
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
        $users->addColumn('totp_secret', Types::STRING, ['length' => 255, 'notnull' => false]);
        $users->addColumn('active', Types::BOOLEAN, ['default' => true]);
        $users->addColumn('theme', Types::STRING, ['length' => 64, 'default' => 'system']);
        $users->addColumn('locale', Types::STRING, ['length' => 16, 'default' => 'en']);
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
        $accounts->addColumn('is_active', Types::BOOLEAN, ['default' => true]);
        $accounts->addColumn('created_at', Types::DATETIME_MUTABLE);
        $accounts->setPrimaryKey(['id']);
        $accounts->addUniqueIndex(['slug'], 'uq_accounts_slug');
        $accounts->addIndex(['owner_user_id'], 'idx_accounts_owner');
        $accounts->addForeignKeyConstraint(
            'users',
            ['owner_user_id'],
            ['id'],
            ['onDelete' => 'RESTRICT'],
            'fk_acc_owner_user_id',
        );

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

        // zone_memberships ---------------------------------------------------
        $zoneMembers = new Table('zone_memberships');
        $zoneMembers->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $zoneMembers->addColumn('account_id', Types::INTEGER);
        $zoneMembers->addColumn('zone_id', Types::STRING, ['length' => 253]);
        $zoneMembers->addColumn('user_id', Types::GUID);
        $zoneMembers->addColumn('role', Types::STRING, ['length' => 32]);
        $zoneMembers->addColumn('granted_by', Types::GUID, ['notnull' => false]);
        $zoneMembers->addColumn('created_at', Types::DATETIME_MUTABLE);
        $zoneMembers->setPrimaryKey(['id']);
        $zoneMembers->addUniqueIndex(['account_id', 'zone_id', 'user_id'], 'uq_zm_account_zone_user');
        $zoneMembers->addForeignKeyConstraint(
            'accounts',
            ['account_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
            'fk_zm_account_id',
        );
        $zoneMembers->addForeignKeyConstraint(
            'users',
            ['user_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
            'fk_zm_user_id',
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
        $pwResetTokens->setPrimaryKey(['id']);
        $pwResetTokens->addUniqueIndex(['token_hash'], 'uq_prt_token_hash');
        $pwResetTokens->addIndex(['user_id'], 'idx_prt_user_id');
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
            $accounts, $accMembers, $provAccounts, $zoneMembers, $impSessions, $auditLogs,
            $pwResetTokens, $systemSettings,
        ];
    }
}

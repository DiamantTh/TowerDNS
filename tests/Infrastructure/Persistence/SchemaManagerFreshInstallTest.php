<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Persistence;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use TowerDNS\Infrastructure\Persistence\SchemaManager;

final class SchemaManagerFreshInstallTest extends TestCase
{
    public function testFreshSchemaSeedsAUsableSuperadminAndOwnerAccount(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $schema     = new SchemaManager($connection);
        $schema->createTablesIfNotExist();
        $schema->seedSystemRoles();
        $schema->seedSystemSettingsDefaults();
        $schema->seedFirstUser('6c74d6ca-2d12-41df-a913-f146bc4785ea', 'admin@example.test', password_hash('safe-password', PASSWORD_ARGON2ID), 'Admin');
        $schema->seedDefaultAccount('6c74d6ca-2d12-41df-a913-f146bc4785ea', 'TowerDNS', 'towerdns', '2026-09-16 00:00:00');

        $schemaManager = $connection->createSchemaManager();
        self::assertTrue($schemaManager->tablesExist([
            'roles', 'role_permissions', 'users', 'user_roles', 'webauthn_credentials', 'api_keys',
            'accounts', 'account_resource_limits', 'account_memberships', 'provider_accounts',
            'managed_zones', 'zone_memberships', 'admin_impersonation_sessions', 'audit_logs',
            'password_reset_tokens', 'system_settings',
        ]));
        self::assertTrue($schemaManager->introspectTable('managed_zones')->hasForeignKey('fk_mz_provider_account'));
        self::assertTrue($schemaManager->introspectTable('zone_memberships')->hasForeignKey('fk_zm_managed_zone_id'));
        self::assertTrue($schemaManager->introspectTable('account_memberships')->hasForeignKey('fk_accm_invited_by'));
        self::assertTrue($schemaManager->introspectTable('admin_impersonation_sessions')->hasForeignKey('fk_ais_effective_account_id'));
        self::assertTrue($schemaManager->introspectTable('password_reset_tokens')->hasIndex('idx_prt_expires_at'));
        self::assertSame('superadmin', $connection->fetchOne('SELECT role_id FROM user_roles WHERE user_id = ?', ['6c74d6ca-2d12-41df-a913-f146bc4785ea']));
        self::assertSame('en-GB', $connection->fetchOne('SELECT locale FROM users WHERE id = ?', ['6c74d6ca-2d12-41df-a913-f146bc4785ea']));
        self::assertSame('en-GB', $connection->fetchOne('SELECT language FROM users WHERE id = ?', ['6c74d6ca-2d12-41df-a913-f146bc4785ea']));
        self::assertSame('UTC', $connection->fetchOne('SELECT timezone FROM users WHERE id = ?', ['6c74d6ca-2d12-41df-a913-f146bc4785ea']));
        self::assertSame('organization', $connection->fetchOne('SELECT account_type FROM accounts WHERE slug = ?', ['towerdns']));
        self::assertSame('owner', $connection->fetchOne('SELECT role FROM account_memberships WHERE user_id = ?', ['6c74d6ca-2d12-41df-a913-f146bc4785ea']));
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM account_resource_limits'));
        self::assertNull($connection->fetchOne('SELECT max_zones FROM account_resource_limits'));
        self::assertSame('6c74d6ca-2d12-41df-a913-f146bc4785ea', $connection->fetchOne('SELECT owner_user_id FROM accounts'));
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM account_memberships WHERE role = ?', ['owner']));

        $schema->seedFirstUser('a5e0ffbd-f299-443d-a0ec-dd44cb85173e', 'second@example.test', password_hash('other-password', PASSWORD_ARGON2ID));
        $schema->seedDefaultAccount('a5e0ffbd-f299-443d-a0ec-dd44cb85173e', 'Second', 'second', '2026-09-16 00:00:01');

        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM users'));
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM accounts'));
    }
}

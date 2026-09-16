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

        self::assertTrue($connection->createSchemaManager()->tablesExist(['account_resource_limits', 'managed_zones', 'zone_memberships', 'provider_accounts', 'audit_logs', 'password_reset_tokens']));
        self::assertSame('superadmin', $connection->fetchOne('SELECT role_id FROM user_roles WHERE user_id = ?', ['6c74d6ca-2d12-41df-a913-f146bc4785ea']));
        self::assertSame('owner', $connection->fetchOne('SELECT role FROM account_memberships WHERE user_id = ?', ['6c74d6ca-2d12-41df-a913-f146bc4785ea']));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM account_resource_limits'));
        self::assertNull($connection->fetchOne('SELECT max_zones FROM account_resource_limits'));
    }
}

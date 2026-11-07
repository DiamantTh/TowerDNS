<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Console;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use TowerDNS\Infrastructure\Console\SchemaValidateCommand;
use TowerDNS\Infrastructure\Persistence\SchemaManager;
use TowerDNS\Infrastructure\Persistence\SchemaMigrationManager;

final class SchemaValidateCommandTest extends TestCase
{
    public function testValidationExitCodesReflectSchemaAndMigrationState(): void
    {
        $db      = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $manager = new SchemaMigrationManager($db, sys_get_temp_dir() . '/towerdns-validation-' . bin2hex(random_bytes(8)) . '.lock');
        $schema  = new SchemaManager($db);
        $schema->createTablesIfNotExist();
        $schema->seedSystemRoles();
        $schema->seedSystemSettingsDefaults();
        $schema->seedFirstUser('test-admin', 'admin@example.test', password_hash('safe-password', PASSWORD_ARGON2ID), 'Admin');
        $tester = new CommandTester(new SchemaValidateCommand($manager));
        self::assertNotSame(0, $tester->execute([]));
        self::assertStringContainsString('metadata', $tester->getDisplay());
        $manager->migrate();
        self::assertSame(0, $tester->execute([]));
        $db->executeStatement('DELETE FROM towerdns_schema_migrations WHERE version = (SELECT MAX(version) FROM towerdns_schema_migrations)');
        self::assertNotSame(0, $tester->execute([]));
        self::assertStringContainsString('pending', $tester->getDisplay());
        $db->executeStatement('DROP TABLE system_settings');
        self::assertNotSame(0, $tester->execute([]));
        self::assertStringContainsString('incomplete', $tester->getDisplay());
    }
}

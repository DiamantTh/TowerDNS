<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Console;

use PHPUnit\Framework\TestCase;
use TowerDNS\Infrastructure\Console\InstallCommand;

/** @psalm-api Runtime discovery by PHPUnit or local module loading is not statically visible. */
final class InstallCommandPrerequisiteTest extends TestCase
{
    public function testReportsEveryMissingRequiredExtensionIncludingSodium(): void
    {
        $missing = InstallCommand::missingRequiredExtensions();

        foreach (['pdo', 'openssl', 'sodium', 'intl', 'mbstring'] as $extension) {
            self::assertSame(!extension_loaded($extension), in_array($extension, $missing, true));
        }
    }

    public function testSqliteInstallerConnectionEnforcesForeignKeys(): void
    {
        $command    = new InstallCommand(sys_get_temp_dir());
        $method     = new \ReflectionMethod($command, 'buildConnection');
        $connection = $method->invoke($command, ['driver' => 'pdo_sqlite', 'path' => ':memory:']);

        self::assertSame(1, (int) $connection->fetchOne('PRAGMA foreign_keys'));
    }
}

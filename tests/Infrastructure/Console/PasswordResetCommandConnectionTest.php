<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Console;

use PHPUnit\Framework\TestCase;
use TowerDNS\Infrastructure\Console\PasswordResetCommand;

final class PasswordResetCommandConnectionTest extends TestCase
{
    public function testSqliteConnectionUsesRuntimeForeignKeyConfiguration(): void
    {
        $command = new PasswordResetCommand(sys_get_temp_dir());
        $method = new \ReflectionMethod($command, 'buildConnection');
        $connection = $method->invoke($command, ['database' => ['driver' => 'pdo_sqlite', 'sqlite' => ['path' => ':memory:']]]);

        self::assertSame(1, (int) $connection->fetchOne('PRAGMA foreign_keys'));
    }
}

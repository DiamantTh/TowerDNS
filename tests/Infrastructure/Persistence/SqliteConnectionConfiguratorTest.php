<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Persistence;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use TowerDNS\Infrastructure\Persistence\SqliteConnectionConfigurator;

final class SqliteConnectionConfiguratorTest extends TestCase
{
    public function testEnablesSqliteForeignKeys(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        $this->assertSame(0, (int) $connection->fetchOne('PRAGMA foreign_keys'));

        new SqliteConnectionConfigurator()->configure($connection);

        $this->assertSame(1, (int) $connection->fetchOne('PRAGMA foreign_keys'));
    }
}

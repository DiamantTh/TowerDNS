<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Persistence;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use TowerDNS\Infrastructure\Persistence\SchemaMigrationLock;
use TowerDNS\Infrastructure\Persistence\SchemaMigrationLockedException;

final class SchemaMigrationLockTest extends TestCase
{
    public function testASecondFileBasedUpgradeCannotAcquireTheSameLock(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $path       = sys_get_temp_dir() . '/towerdns-lock-' . bin2hex(random_bytes(8));
        $first      = new SchemaMigrationLock($connection, $path)->acquire();

        try {
            $this->expectException(SchemaMigrationLockedException::class);
            new SchemaMigrationLock($connection, $path)->acquire();
        } finally {
            $first->release();
            @unlink($path);
        }
    }
}

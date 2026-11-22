<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Console;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use TowerDNS\Application\Module\LocalModuleDiscovery;
use TowerDNS\Infrastructure\Console\UpgradeCheckCommand;
use TowerDNS\Infrastructure\Persistence\SchemaMigrationManager;
use TowerDNS\Infrastructure\Persistence\SqliteConnectionConfigurator;

/** @psalm-api Discovered by PHPUnit's test suite. */
final class UpgradeCheckCommandTest extends TestCase
{
    public function testJsonPreflightIsReadOnlyAndDoesNotExposeConfiguredSecrets(): void
    {
        $root = sys_get_temp_dir() . '/towerdns-upgrade-check-' . bin2hex(random_bytes(6));
        foreach (['configs', 'data', 'cache/ratelimit', 'logs', 'modules'] as $directory) {
            mkdir($root . '/' . $directory, 0o750, true);
        }
        $secret = base64_encode(random_bytes(32));
        file_put_contents($root . '/configs/config.local.toml', "[security]\nencryption_key = \"{$secret}\"\n");
        file_put_contents($root . '/configs/database.toml', "[database]\ndriver = \"pdo_sqlite\"\n");

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        new SqliteConnectionConfigurator()->configure($connection);
        $manager = new SchemaMigrationManager($connection, $root . '/data/migrations.lock');
        $command = new UpgradeCheckCommand($root, $connection, $manager, new LocalModuleDiscovery($root . '/modules'));
        $tester  = new CommandTester($command);

        try {
            self::assertSame(1, $tester->execute(['--format' => 'json']));
            $result = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
            self::assertFalse($result['ready']);
            self::assertSame('configured', array_column($result['checks'], 'status', 'check')['application_key']);
            self::assertStringNotContainsString($secret, $tester->getDisplay());
            self::assertSame([], $connection->createSchemaManager()->introspectTableNames(), 'The preflight must not mutate schema.');
        } finally {
            $connection->close();
            self::removeDirectory($root);
        }
    }

    private static function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $entries = scandir($path, SCANDIR_SORT_NONE);
        if (!is_array($entries)) {
            return;
        }
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $entry = $path . '/' . $name;
            is_dir($entry) ? self::removeDirectory($entry) : unlink($entry);
        }
        rmdir($path);
    }
}

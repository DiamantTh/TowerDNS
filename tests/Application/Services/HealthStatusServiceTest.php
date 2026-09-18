<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Application\Services;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use TowerDNS\Application\Module\LocalModuleDiscovery;
use TowerDNS\Application\Services\CredentialService;
use TowerDNS\Application\Services\HealthStatusService;
use TowerDNS\Infrastructure\Persistence\SchemaManager;

final class HealthStatusServiceTest extends TestCase
{
    public function testReportsHealthyStatusWithoutLeakingSecrets(): void
    {
        if (!extension_loaded('sodium')) {
            self::markTestSkipped('libsodium is required for encryption-key health validation.');
        }

        $base = sys_get_temp_dir() . '/towerdns-health-' . bin2hex(random_bytes(8));
        mkdir($base . '/configs', 0o750, true);
        mkdir($base . '/data', 0o750, true);
        mkdir($base . '/cache/ratelimit', 0o750, true);
        mkdir($base . '/logs', 0o750, true);

        $configPath   = $base . '/configs/config.local.toml';
        $providerPath = $base . '/configs/providers.toml';
        file_put_contents($configPath, "[security]\nencryption_key = \"" . CredentialService::generateKey() . "\"\n");
        file_put_contents($providerPath, "# provider config\n");

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        new SchemaManager($connection)->createTablesIfNotExist();

        $service = new HealthStatusService(
            $connection,
            $base,
            $configPath,
            $providerPath,
            new LocalModuleDiscovery($base . '/modules'),
        );

        $status = $service->check();

        $payload = json_encode($status);
        self::assertIsString($payload);
        self::assertSame('ok', $status['status']);
        self::assertTrue($status['ready']);
        self::assertSame('ok', $status['checks']['database']['summary']);
        self::assertStringNotContainsString('encryption_key', $payload);
    }

    public function testReadinessFailsCleanlyWhenConfigIsBroken(): void
    {
        $base = sys_get_temp_dir() . '/towerdns-health-bad-' . bin2hex(random_bytes(8));
        mkdir($base . '/configs', 0o750, true);
        mkdir($base . '/data', 0o750, true);
        mkdir($base . '/cache/ratelimit', 0o750, true);
        mkdir($base . '/logs', 0o750, true);

        $configPath   = $base . '/configs/config.local.toml';
        $providerPath = $base . '/configs/providers.toml';
        file_put_contents($configPath, "[security]\nkey = \"secret-value\"\n");
        file_put_contents($providerPath, "# provider config\n");

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        new SchemaManager($connection)->createTablesIfNotExist();

        $service = new HealthStatusService(
            $connection,
            $base,
            $configPath,
            $providerPath,
            new LocalModuleDiscovery($base . '/modules'),
        );

        $status  = $service->readiness();
        $payload = json_encode($status);
        self::assertIsString($payload);

        self::assertSame('fail', $status['status']);
        self::assertFalse($status['ready']);
        self::assertFalse($status['checks']['config']['ok']);
        self::assertStringNotContainsString('secret-value', $payload);
    }
}

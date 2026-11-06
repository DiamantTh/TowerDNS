<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Application\Services;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use TowerDNS\Application\Module\LocalModuleDiscovery;
use TowerDNS\Application\Services\CredentialService;
use TowerDNS\Application\Services\HealthStatusService;
use TowerDNS\Infrastructure\Persistence\SchemaManager;

/** @psalm-api Runtime discovery by PHPUnit or local module loading is not statically visible. */
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

        $this->prepareFrontend($base);

        $configPath   = $base . '/configs/config.local.toml';
        $providerPath = $base . '/configs/providers.toml';
        file_put_contents($configPath, "[security]\nencryption_key = \"" . CredentialService::generateKey() . "\"\n");
        file_put_contents($providerPath, "# provider config\n");

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->prepareSchema($connection);

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
        unlink($base . '/httpdocs/assets/app-abcdefgh.js.br');
        self::assertSame('degraded', $service->readiness()['status']);
        self::assertTrue($service->readiness()['ready']);
        self::assertSame('degraded', $service->readiness()['checks']['frontend_assets']['summary']);
        $request = new \Laminas\Diactoros\ServerRequest()->withUri(new \Laminas\Diactoros\Uri('/ready'));
        self::assertSame(200, new \TowerDNS\Infrastructure\Http\Handler\HealthHandler($service)->handle($request)->getStatusCode());
        unlink($base . '/httpdocs/assets/app-abcdefgh.js');
        self::assertSame('broken', $service->readiness()['checks']['frontend_assets']['summary']);
        self::assertFalse($service->readiness()['ready']);
        self::assertSame(503, new \TowerDNS\Infrastructure\Http\Handler\HealthHandler($service)->handle($request)->getStatusCode());
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
        $this->prepareSchema($connection);

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

    public function testHealthChecksDoNotCreateMissingRuntimeDirectories(): void
    {
        $base = sys_get_temp_dir() . '/towerdns-health-no-side-effects-' . bin2hex(random_bytes(8));
        mkdir($base . '/configs', 0o750, true);

        $service = new HealthStatusService(projectRoot: $base);
        $service->check();

        self::assertDirectoryDoesNotExist($base . '/data');
        self::assertDirectoryDoesNotExist($base . '/cache/ratelimit');
        self::assertDirectoryDoesNotExist($base . '/logs');
    }

    public function testSchemaReadinessRejectsPendingMissingAndInvalidMigrationState(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->prepareSchema($connection);
        $service = new HealthStatusService($connection);
        self::assertTrue($service->readiness()['checks']['schema']['ok']);
        $connection->executeStatement('DELETE FROM towerdns_schema_migrations WHERE version = (SELECT MAX(version) FROM towerdns_schema_migrations)');
        self::assertFalse($service->readiness()['checks']['schema']['ok']);
        self::assertFalse($service->readiness()['ready']);
        $connection->executeStatement('DROP TABLE towerdns_schema_migrations');
        self::assertFalse($service->readiness()['checks']['schema']['ok']);
        $connection->executeStatement('DROP TABLE system_settings');
        self::assertFalse($service->readiness()['checks']['schema']['ok']);
    }
    private function prepareSchema(\Doctrine\DBAL\Connection $connection): void
    {
        $schema = new SchemaManager($connection);
        $schema->createTablesIfNotExist();
        $schema->seedSystemRoles();
        $schema->seedSystemSettingsDefaults();
        $schema->seedFirstUser('health-admin', 'admin@example.test', password_hash('safe-password', PASSWORD_ARGON2ID), 'Admin');
        new \TowerDNS\Infrastructure\Persistence\SchemaMigrationManager($connection)->migrate();
    }

    private function prepareFrontend(string $root): void
    {
        mkdir($root . '/themes/default/src', 0o750, true);
        mkdir($root . '/httpdocs/assets', 0o750, true);
        mkdir($root . '/build', 0o750, true);
        $sources = [];
        foreach (['package.json', 'package-lock.json', 'vite.config.ts', 'svelte.config.js', 'tsconfig.json'] as $name) {
            file_put_contents($root . '/' . $name, $name);
            $sources[$name] = hash('sha256', $name);
        }
        $assets = [];
        foreach (['app-abcdefgh.js', 'app-abcdefgh.css', 'theme-abcdefgh.js'] as $name) {
            foreach (['', '.br', '.zst'] as $suffix) {
                file_put_contents($root . '/httpdocs/assets/' . $name . $suffix, $name . $suffix);
                $assets[$name . $suffix] = hash('sha256', $name . $suffix);
            }
        }
        $manifest = json_encode([
            'themes/default/src/app/main.ts'       => ['file' => 'app-abcdefgh.js', 'isEntry' => true, 'css' => ['app-abcdefgh.css']],
            'themes/default/src/app/theme-init.ts' => ['file' => 'theme-abcdefgh.js', 'isEntry' => true],
        ], JSON_THROW_ON_ERROR);
        file_put_contents($root . '/build/asset-manifest.json', $manifest);
        file_put_contents($root . '/build/asset-release.json', json_encode(['manifestSha256' => hash('sha256', $manifest), 'sources' => $sources, 'assets' => $assets, 'phpSources' => []], JSON_THROW_ON_ERROR));
        new \TowerDNS\Infrastructure\Ui\AssetManifest($root)->activate();
    }
}

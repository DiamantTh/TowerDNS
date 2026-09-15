<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Application\Module;

use PHPUnit\Framework\TestCase;
use TowerDNS\Application\Module\LocalModuleDiscovery;
use TowerDNS\Application\Module\ModuleManifest;
use TowerDNS\Application\Module\ModuleType;
use TowerDNS\Application\Module\PermissionContributorInterface;
use TowerDNS\Domain\Auth\PermissionDefinition;

final class LocalModuleDiscoveryTest extends TestCase
{
    private string $modulesDirectory;

    protected function setUp(): void
    {
        $this->modulesDirectory = sys_get_temp_dir() . '/towerdns-modules-' . bin2hex(random_bytes(8));
        mkdir($this->modulesDirectory, 0o700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->modulesDirectory . '/*/module.php') ?: [] as $file) {
            unlink($file);
            rmdir(dirname($file));
        }
        rmdir($this->modulesDirectory);
    }

    public function testDiscoversOfficialFolderSpellingWithoutDerivingTheId(): void
    {
        $this->writeManifest('TLSA', 'towerdns.tlsa', 'TLSA', 'feature');
        $this->writeManifest('deSEC', 'towerdns.desec', 'deSEC', 'provider');

        $modules = new LocalModuleDiscovery($this->modulesDirectory)->discover();

        self::assertSame(['towerdns.desec', 'towerdns.tlsa'], array_column($modules, 'id'));
        self::assertSame([ModuleType::PROVIDER, ModuleType::FEATURE], array_column($modules, 'type'));
    }

    public function testRejectsCaseCollidingModuleFolders(): void
    {
        $this->writeManifest('TLSA', 'towerdns.tlsa', 'TLSA', 'feature');
        $this->writeManifest('tlsa', 'towerdns.tlsa-alt', 'tlsa', 'feature');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Case-colliding module directories');
        new LocalModuleDiscovery($this->modulesDirectory)->discover();
    }

    public function testRejectsNonNormalizedTechnicalIds(): void
    {
        $this->writeManifest('TLSA', 'towerdns.TLSA', 'TLSA', 'feature');

        $this->expectException(\InvalidArgumentException::class);
        new LocalModuleDiscovery($this->modulesDirectory)->discover();
    }

    public function testOnlyEnabledModulesContributePermissions(): void
    {
        $this->writePermissionModule('TLSA', 'towerdns.tlsa', 'towerdns.tlsa.manage');
        $this->writePermissionModule('Monitor', 'towerdns.monitor', 'towerdns.tlsa.manage');

        $discovery = new LocalModuleDiscovery($this->modulesDirectory, enabledModuleIds: ['towerdns.tlsa']);

        self::assertSame(['towerdns.tlsa'], array_column($discovery->discover(), 'id'));
        self::assertSame(['towerdns.tlsa.manage'], array_map(
            static fn(PermissionDefinition $definition): string => $definition->id,
            $discovery->permissionDefinitions(),
        ));
    }

    public function testOrdersDependenciesBeforeDependantsRegardlessOfFolderOrder(): void
    {
        $this->writeManifest('ZFeature', 'towerdns.feature', 'Feature', 'feature', ['towerdns.base']);
        $this->writeManifest('ABase', 'towerdns.base', 'Base', 'integration');
        $this->writeManifest('Monitor', 'towerdns.monitor', 'Monitor', 'feature');

        self::assertSame(
            ['towerdns.base', 'towerdns.feature', 'towerdns.monitor'],
            array_column(new LocalModuleDiscovery($this->modulesDirectory)->discover(), 'id'),
        );
    }

    public function testRejectsDependencyCyclesAndMissingOrDisabledDependencies(): void
    {
        $this->writeManifest('One', 'towerdns.one', 'One', 'feature', ['towerdns.two']);
        $this->writeManifest('Two', 'towerdns.two', 'Two', 'feature', ['towerdns.one']);

        try {
            new LocalModuleDiscovery($this->modulesDirectory)->discover();
            self::fail('Expected module dependency cycle to be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('Module dependency cycle', $exception->getMessage());
        }

        foreach (glob($this->modulesDirectory . '/*/module.php') ?: [] as $file) {
            unlink($file);
            rmdir(dirname($file));
        }

        $this->writeManifest('Feature', 'towerdns.feature', 'Feature', 'feature', ['towerdns.missing']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('requires missing module');
        new LocalModuleDiscovery($this->modulesDirectory)->discover();
    }

    public function testRejectsUnsupportedTowerDnsConstraintSyntax(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ModuleManifest('towerdns.test', 'Test', '1.0.0', ModuleType::FEATURE, '^1.0');
    }

    /** @param list<string> $dependencies */
    private function writeManifest(string $directory, string $id, string $displayName, string $type, array $dependencies = []): void
    {
        mkdir($this->modulesDirectory . '/' . $directory, 0o700);
        $manifest = sprintf(
            "<?php\nreturn new \\TowerDNS\\Application\\Module\\ModuleManifest('%s', '%s', '1.0.0', \\TowerDNS\\Application\\Module\\ModuleType::%s, '>=1.0.0', %s);\n",
            $id,
            $displayName,
            strtoupper($type),
            var_export($dependencies, true),
        );
        file_put_contents($this->modulesDirectory . '/' . $directory . '/module.php', $manifest);
    }

    private function writePermissionModule(string $directory, string $id, string $permission): void
    {
        mkdir($this->modulesDirectory . '/' . $directory, 0o700);
        $module = sprintf(
            "<?php\nreturn new \\TowerDNS\\Tests\\Application\\Module\\TestPermissionModule('%s', '%s');\n",
            $id,
            $permission,
        );
        file_put_contents($this->modulesDirectory . '/' . $directory . '/module.php', $module);
    }
}

final readonly class TestPermissionModule implements PermissionContributorInterface
{
    public function __construct(private string $id, private string $permission) {}

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest($this->id, $this->id, '1.0.0', ModuleType::FEATURE);
    }

    public function permissionDefinitions(): iterable
    {
        yield new PermissionDefinition($this->permission, $this->permission);
    }
}

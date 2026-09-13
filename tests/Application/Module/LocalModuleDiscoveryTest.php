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

        self::assertSame(['towerdns.tlsa', 'towerdns.desec'], array_column($modules, 'id'));
        self::assertSame([ModuleType::FEATURE, ModuleType::PROVIDER], array_column($modules, 'type'));
    }

    public function testRejectsCaseCollidingModuleFolders(): void
    {
        $this->writeManifest('TLSA', 'towerdns.tlsa', 'TLSA', 'feature');
        $this->writeManifest('tlsa', 'towerdns.tlsa-alt', 'tlsa', 'feature');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Case-kollidierende Modulordner');
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

    private function writeManifest(string $directory, string $id, string $displayName, string $type): void
    {
        mkdir($this->modulesDirectory . '/' . $directory, 0o700);
        $manifest = sprintf(
            "<?php\nreturn new \\TowerDNS\\Application\\Module\\ModuleManifest('%s', '%s', '1.0.0', \\TowerDNS\\Application\\Module\\ModuleType::%s);\n",
            $id,
            $displayName,
            strtoupper($type),
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

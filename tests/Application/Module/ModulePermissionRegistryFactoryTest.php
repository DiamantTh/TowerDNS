<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Application\Module;

use PHPUnit\Framework\TestCase;
use TowerDNS\Application\Module\LocalModuleDiscovery;
use TowerDNS\Application\Module\ModulePermissionRegistryFactory;
use TowerDNS\Domain\Auth\PermissionDefinition;

final class ModulePermissionRegistryFactoryTest extends TestCase
{
    private string $modulesDirectory;

    protected function setUp(): void
    {
        $this->modulesDirectory = sys_get_temp_dir() . '/towerdns-module-permissions-' . bin2hex(random_bytes(8));
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

    public function testRejectsCaseInsensitiveCoreAndModulePermissionCollision(): void
    {
        mkdir($this->modulesDirectory . '/Feature', 0o700);
        file_put_contents(
            $this->modulesDirectory . '/Feature/module.php',
            "<?php\nreturn new \\TowerDNS\\Tests\\Application\\Module\\RegistryTestPermissionModule('towerdns.feature', 'ZONE.READ');\n",
        );

        $this->expectException(\LogicException::class);
        new ModulePermissionRegistryFactory(new LocalModuleDiscovery($this->modulesDirectory))->create();
    }
}

final readonly class RegistryTestPermissionModule implements \TowerDNS\Application\Module\PermissionContributorInterface
{
    public function __construct(private string $id, private string $permission) {}

    public function manifest(): \TowerDNS\Application\Module\ModuleManifest
    {
        return new \TowerDNS\Application\Module\ModuleManifest(
            $this->id,
            $this->id,
            '1.0.0',
            \TowerDNS\Application\Module\ModuleType::FEATURE,
        );
    }

    public function permissionDefinitions(): iterable
    {
        yield new PermissionDefinition($this->permission, $this->permission);
    }
}

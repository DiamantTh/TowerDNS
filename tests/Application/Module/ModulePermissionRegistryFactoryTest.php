<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Application\Module;

use PHPUnit\Framework\TestCase;
use TowerDNS\Application\Module\LocalModuleDiscovery;
use TowerDNS\Application\Module\ModulePermissionRegistryFactory;
use TowerDNS\Domain\Auth\PermissionDefinition;

/**
 * @psalm-api Runtime discovery by PHPUnit or local module loading is not statically visible.
 * @psalm-suppress PropertyNotSetInConstructor PHPUnit initializes fixture paths in setUp().
 */
final class ModulePermissionRegistryFactoryTest extends TestCase
{
    private string $modulesDirectory;

    #[\Override]
    protected function setUp(): void
    {
        $this->modulesDirectory = sys_get_temp_dir() . '/towerdns-module-permissions-' . bin2hex(random_bytes(8));
        mkdir($this->modulesDirectory, 0o700);
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->globPaths($this->modulesDirectory . '/*/module.php') as $file) {
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

    /** @return list<non-empty-string> */
    private function globPaths(string $pattern): array
    {
        $paths = glob($pattern);

        return $paths === false ? [] : array_map($this->nonEmptyPath(...), $paths);
    }

    /** @return non-empty-string */
    private function nonEmptyPath(string $path): string
    {
        if ($path === '') {
            throw new \UnexpectedValueException('glob returned an empty path.');
        }

        return $path;
    }
}

/** @psalm-api Runtime discovery by PHPUnit or local module loading is not statically visible. */
final readonly class RegistryTestPermissionModule implements \TowerDNS\Application\Module\PermissionContributorInterface
{
    public function __construct(private string $id, private string $permission) {}

    #[\Override]
    public function manifest(): \TowerDNS\Application\Module\ModuleManifest
    {
        return new \TowerDNS\Application\Module\ModuleManifest(
            $this->id,
            $this->id,
            '1.0.0',
            \TowerDNS\Application\Module\ModuleType::FEATURE,
        );
    }

    #[\Override]
    public function permissionDefinitions(): iterable
    {
        yield new PermissionDefinition($this->permission, $this->permission);
    }
}

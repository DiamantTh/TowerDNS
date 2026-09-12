<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Application\Module;

use PHPUnit\Framework\TestCase;
use TowerDNS\Application\Module\LocalModuleDiscovery;
use TowerDNS\Application\Module\ModuleType;

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
}

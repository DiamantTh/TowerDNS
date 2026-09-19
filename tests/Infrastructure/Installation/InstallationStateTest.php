<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Installation;

use PHPUnit\Framework\TestCase;
use TowerDNS\Infrastructure\Installation\InstallationState;

final class InstallationStateTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/towerdns-install-state-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/configs', 0o750, true);
        mkdir($this->root . '/install', 0o750, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testFreshCheckoutIsUnlocked(): void
    {
        self::assertFalse(InstallationState::isLocked($this->root));
    }

    public function testMarkerAndLegacyLockLockTheInstallation(): void
    {
        touch($this->root . '/configs/.installed');
        self::assertTrue(InstallationState::isLocked($this->root));

        unlink($this->root . '/configs/.installed');
        touch($this->root . '/install/.lock');
        self::assertTrue(InstallationState::isLocked($this->root));
    }

    public function testCompleteConfigurationOnlyLocksAfterInstallerRemoval(): void
    {
        foreach (['config.local.toml', 'database.toml', 'providers.toml'] as $file) {
            touch($this->root . '/configs/' . $file);
        }

        self::assertFalse(InstallationState::isLocked($this->root));

        rmdir($this->root . '/install');
        self::assertTrue(InstallationState::isLocked($this->root));
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}

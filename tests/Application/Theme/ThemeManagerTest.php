<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Application\Theme;

use PHPUnit\Framework\TestCase;
use TowerDNS\Application\Theme\ThemeManager;

final class ThemeManagerTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/towerdns-theme-' . bin2hex(random_bytes(6));
        mkdir($this->projectRoot . '/themes/default', 0o777, true);
        mkdir($this->projectRoot . '/templates/app', 0o777, true);
        file_put_contents($this->projectRoot . '/themes/default/theme.json', json_encode([
            'name'           => 'Default',
            'description'    => 'Test theme',
            'skeleton_theme' => 'cerberus',
        ], JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->projectRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->projectRoot);
    }

    public function testConfiguredAndPreferredThemesAreResolved(): void
    {
        mkdir($this->projectRoot . '/themes/modern');
        file_put_contents($this->projectRoot . '/themes/modern/theme.json', json_encode([
            'name'           => 'Modern',
            'skeleton_theme' => 'modern',
        ], JSON_THROW_ON_ERROR));

        $manager = new ThemeManager($this->projectRoot, 'modern');

        self::assertSame('modern', $manager->getActive()->name);
        self::assertSame('default', $manager->getActive('default')->name);
        self::assertSame('modern', $manager->getActive('system')->name);
    }

    public function testUnknownAndInvalidThemesFallBackSafely(): void
    {
        mkdir($this->projectRoot . '/themes/broken');
        file_put_contents($this->projectRoot . '/themes/broken/theme.json', '{invalid');

        $manager = new ThemeManager($this->projectRoot, '../outside');

        self::assertFalse($manager->has('broken'));
        self::assertSame('default', $manager->getActive('../outside')->name);
        self::assertArrayNotHasKey('../outside', $manager->getAvailable());
    }
}

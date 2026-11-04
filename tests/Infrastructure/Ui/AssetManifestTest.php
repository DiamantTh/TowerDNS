<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Ui;

use PHPUnit\Framework\TestCase;
use TowerDNS\Infrastructure\Ui\AssetManifest;
use TowerDNS\Infrastructure\Ui\AssetManifestException;

/** @psalm-api */
final class AssetManifestTest extends TestCase
{
    private string $root = '';

    #[\Override]
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/towerdns-assets-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/httpdocs/assets', 0o777, true);
        mkdir($this->root . '/themes/default/src', 0o777, true);
        mkdir($this->root . '/build', 0o777, true);
        $sources      = ['package.json', 'package-lock.json', 'vite.config.ts', 'svelte.config.js', 'tsconfig.json', 'themes/default/src/main.ts'];
        $sourceHashes = [];
        foreach ($sources as $name) {
            file_put_contents($this->root . '/' . $name, $name);
            $sourceHashes[$name] = hash('sha256', $name);
        }
        $assets = [];
        foreach (['app-abcdefgh.js', 'app-abcdefgh.css', 'theme-init-abcdefgh.js', 'feature-abcdefgh.js'] as $name) {
            foreach (['', '.br', '.zst'] as $suffix) {
                $content = $name . $suffix;
                file_put_contents($this->root . '/httpdocs/assets/' . $name . $suffix, $content);
                $assets[$name . $suffix] = hash('sha256', $content);
            }
        }
        $manifest = [
            'themes/default/src/app/main.ts'       => ['file' => 'app-abcdefgh.js', 'isEntry' => true, 'css' => ['app-abcdefgh.css'], 'dynamicImports' => ['feature']],
            'themes/default/src/app/theme-init.ts' => ['file' => 'theme-init-abcdefgh.js', 'isEntry' => true],
            'feature'                              => ['file' => 'feature-abcdefgh.js'],
        ];
        $json = json_encode($manifest, JSON_THROW_ON_ERROR);
        file_put_contents($this->root . '/build/asset-manifest.json', $json);
        file_put_contents($this->root . '/build/asset-release.json', json_encode(['manifestSha256' => hash('sha256', $json), 'assets' => $assets, 'sources' => $sourceHashes, 'phpSources' => []], JSON_THROW_ON_ERROR));
        new AssetManifest($this->root)->activate();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    public function testResolvesReleaseWithCompleteDynamicAssets(): void
    {
        self::assertSame(['app' => 'app-abcdefgh.js', 'theme' => 'theme-init-abcdefgh.js', 'css' => ['app-abcdefgh.css']], new AssetManifest($this->root)->resolve());
    }

    public function testRejectsMissingDynamicAssetVariant(): void
    {
        unlink($this->root . '/httpdocs/assets/feature-abcdefgh.js.zst');
        $this->expectException(AssetManifestException::class);
        $this->expectExceptionMessage('missing or inconsistent');
        new AssetManifest($this->root)->verifyComplete();
    }

    public function testRejectsManifestFromAnotherBuild(): void
    {
        file_put_contents($this->root . '/build/asset-manifest.json', '{}');
        $this->expectException(AssetManifestException::class);
        $this->expectExceptionMessage('does not match');
        new AssetManifest($this->root)->resolve();
    }

    public function testRejectsSourceChangedSinceBuild(): void
    {
        file_put_contents($this->root . '/themes/default/src/main.ts', 'changed');
        $this->expectException(AssetManifestException::class);
        $this->expectExceptionMessage('source and asset release do not match');
        new AssetManifest($this->root)->resolve();
    }
    public function testDegradedCompressionAndBrokenOriginal(): void
    {
        $manifest = new AssetManifest($this->root);
        self::assertSame('healthy', $manifest->status());
        unlink($this->root . '/httpdocs/assets/app-abcdefgh.js.br');
        self::assertSame('degraded', $manifest->status());
        self::assertSame('app-abcdefgh.js', $manifest->resolve()['app']);
        $renderer = new \TowerDNS\Infrastructure\Ui\SvelteRenderer(
            new \TowerDNS\Application\Theme\ThemeManager(dirname(__DIR__, 3)),
            assets: $manifest,
        );
        self::assertStringContainsString('/assets/app-abcdefgh.js', $renderer->render('app::login'));
        unlink($this->root . '/httpdocs/assets/app-abcdefgh.js');
        self::assertSame('broken', $manifest->status());
    }

    public function testActivationRejectsIncompleteAssets(): void
    {
        unlink($this->root . '/httpdocs/assets/app-abcdefgh.js.zst');
        $this->expectException(AssetManifestException::class);
        new AssetManifest($this->root)->activate();
    }

    public function testMissingOrCorruptActivationIsBroken(): void
    {
        file_put_contents($this->root . '/build/asset-activation.json', '{');
        self::assertSame('broken', new AssetManifest($this->root)->status());
        unlink($this->root . '/build/asset-activation.json');
        self::assertSame('broken', new AssetManifest($this->root)->status());
    }

    public function testCorruptOriginalIsBroken(): void
    {
        file_put_contents($this->root . '/httpdocs/assets/app-abcdefgh.js', 'corrupt');
        self::assertSame('broken', new AssetManifest($this->root)->status());
    }

    public function testActivationRejectsConflictingRetainedAsset(): void
    {
        $record = json_encode(['schema' => 1, 'release' => json_encode(['assets' => ['app-abcdefgh.js' => str_repeat('0', 64)]], JSON_THROW_ON_ERROR)], JSON_THROW_ON_ERROR);
        file_put_contents($this->root . '/httpdocs/assets/.releases/' . hash('sha256', $record) . '.json', $record);
        self::assertSame('broken', new AssetManifest($this->root)->status());
        $this->expectException(AssetManifestException::class);
        $this->expectExceptionMessage('Conflicting frontend asset');
        new AssetManifest($this->root)->activate();
    }
}

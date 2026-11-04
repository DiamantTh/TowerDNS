<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Ui;

/** Validates the release-local Vite manifest and its complete asset inventory. */
final readonly class AssetManifest
{
    public function __construct(private string $root) {}

    public function verifyComplete(): void
    {
        $manifestPath = $this->root . '/build/asset-manifest.json';
        $releasePath  = $this->root . '/build/asset-release.json';
        $manifestRaw  = @file_get_contents($manifestPath);
        $releaseRaw   = @file_get_contents($releasePath);
        if ($manifestRaw === false || $releaseRaw === false) {
            throw new AssetManifestException('Frontend asset manifest or release inventory is missing.');
        }
        try {
            $manifest = json_decode($manifestRaw, true, 512, JSON_THROW_ON_ERROR);
            $release  = json_decode($releaseRaw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new AssetManifestException('Frontend asset metadata is invalid.', $exception->getCode(), previous: $exception);
        }
        if (!is_array($manifest) || !is_array($release) || !is_string($release['manifestSha256'] ?? null)
                                 || !hash_equals($release['manifestSha256'], hash('sha256', $manifestRaw))
                                 || !is_array($release['assets'] ?? null) || !is_array($release['sources'] ?? null) || !is_array($release['phpSources'] ?? null)) {
            throw new AssetManifestException('Frontend manifest and release inventory do not match.');
        }

        if ($release['phpSources'] !== $this->phpSources()) {
            throw new AssetManifestException('PHP source and frontend build do not match.');
        }
        foreach ($release['assets'] as $name => $hash) {
            if (!is_string($name) || !preg_match('/\A[A-Za-z0-9_.-]+\z/D', $name) || !is_string($hash)
                                  || !$this->matchesHash($this->root . '/httpdocs/assets/' . $name, $hash)) {
                throw new AssetManifestException('Frontend asset is missing or inconsistent: ' . (is_string($name) ? $name : 'invalid name'));
            }
        }
        $sourcePaths = ['package.json', 'package-lock.json', 'vite.config.ts', 'svelte.config.js', 'tsconfig.json'];
        $sourceRoot  = $this->root . '/themes/default/src';
        if (!is_dir($sourceRoot)) {
            throw new AssetManifestException('Frontend source is missing from the release.');
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($sourceRoot, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $sourcePaths[] = substr($file->getPathname(), strlen($this->root) + 1);
            }
        }
        sort($sourcePaths);
        $listedSources = [];
        foreach ($release['sources'] as $path => $_hash) {
            if (!is_string($path)) {
                throw new AssetManifestException('Frontend source inventory is invalid.');
            }
            $listedSources[] = $path;
        }
        sort($listedSources);
        if ($sourcePaths !== $listedSources) {
            throw new AssetManifestException('Frontend source and asset release do not match.');
        }
        foreach ($release['sources'] as $path => $hash) {
            if (!is_string($hash) || !$this->matchesHash($this->root . '/' . $path, $hash)) {
                throw new AssetManifestException('Frontend source and asset release do not match.');
            }
        }

        foreach ($manifest as $entry) {
            if (!is_array($entry) || !is_string($entry['file'] ?? null)) {
                throw new AssetManifestException('Frontend manifest contains an invalid entry.');
            }
            foreach (['css', 'assets', 'imports', 'dynamicImports'] as $field) {
                if (isset($entry[$field]) && !is_array($entry[$field])) {
                    throw new AssetManifestException('Frontend manifest contains an invalid dependency list.');
                }
            }
            $names = array_merge([$entry['file']], $entry['css'] ?? [], $entry['assets'] ?? []);
            foreach ($names as $name) {
                if (!is_string($name) || !isset($release['assets'][$name])) {
                    throw new AssetManifestException('Frontend manifest references an unverified asset.');
                }
                if (preg_match('/\.(js|css)\z/D', $name) === 1
                    && (preg_match('/\A[A-Za-z0-9_-]+-[A-Za-z0-9_-]{8}\.(js|css)\z/D', $name) !== 1
                        || !isset($release['assets'][$name . '.br']) || !isset($release['assets'][$name . '.zst']))) {
                    throw new AssetManifestException('Frontend hashed or compressed asset is missing.');
                }
            }
            foreach (array_merge($entry['imports'] ?? [], $entry['dynamicImports'] ?? []) as $dependency) {
                if (!is_string($dependency) || !isset($manifest[$dependency])) {
                    throw new AssetManifestException('Frontend manifest references a missing module.');
                }
            }
        }
        $app   = $manifest['themes/default/src/app/main.ts']       ?? null;
        $theme = $manifest['themes/default/src/app/theme-init.ts'] ?? null;
        if (!is_array($app) || ($app['isEntry'] ?? null) !== true || !is_array($theme) || ($theme['isEntry'] ?? null) !== true
                            || !isset($app['file'], $theme['file']) || !is_array($app['css'] ?? null) || $app['css'] === []) {
            throw new AssetManifestException('Frontend manifest is missing required entries or styles.');
        }
    }

    /** Fully validate a release, then publish its immutable, persistent record. */
    public function activate(): void
    {
        $this->verifyComplete();
        $release  = file_get_contents($this->root . '/build/asset-release.json');
        $manifest = file_get_contents($this->root . '/build/asset-manifest.json');
        if (!is_string($release) || !is_string($manifest)) {
            throw new AssetManifestException('Release metadata disappeared during activation.');
        }
        $record = json_encode(['schema' => 1, 'release' => $release, 'manifest' => $manifest, 'phpSource' => $this->phpSourceDigest()], JSON_THROW_ON_ERROR);
        $id     = hash('sha256', $record);
        $dir    = $this->root . '/httpdocs/assets/.releases';
        if (!is_dir($dir) && !@mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new AssetManifestException('Cannot create release registry.');
        }
        $inventory = json_decode($release, true, 512, JSON_THROW_ON_ERROR)['assets'];
        $this->assertCompatible($inventory);
        $this->verifyComplete();
        if (file_get_contents($this->root . '/build/asset-release.json')     !== $release
            || file_get_contents($this->root . '/build/asset-manifest.json') !== $manifest
            || (json_decode($record, true)['phpSource'] ?? null)             !== $this->phpSourceDigest()) {
            throw new AssetManifestException('Release changed during activation.');
        }
        $this->atomicWrite($dir . '/' . $id . '.json', $record);
        $this->atomicWrite($this->root . '/build/asset-activation.json', json_encode(['schema' => 1, 'id' => $id], JSON_THROW_ON_ERROR));
    }

    /** @return array{app: string, theme: string, css: list<string>} */
    public function resolve(): array
    {
        [$manifest, $release] = $this->activated();
        $paths                = ['package.json', 'package-lock.json', 'vite.config.ts', 'svelte.config.js', 'tsconfig.json'];
        $sourceRoot           = $this->root . '/themes/default/src';
        if (!is_dir($sourceRoot)) {
            throw new AssetManifestException('Frontend source is missing.');
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($sourceRoot, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $paths[] = substr($file->getPathname(), strlen($this->root) + 1);
            }
        }
        sort($paths);
        $listed = [];
        foreach ($release['sources'] as $path => $_hash) {
            if (!is_string($path)) {
                throw new AssetManifestException('Invalid frontend source path.');
            }
            $listed[] = $path;
        }
        sort($listed);
        if ($paths !== $listed) {
            throw new AssetManifestException('Frontend source inventory does not match.');
        }
        foreach ($release['sources'] as $path => $hash) {
            if (!is_string($path) || !is_string($hash) || !$this->matchesHash($this->root . '/' . $path, $hash)) {
                throw new AssetManifestException('Frontend source and asset release do not match.');
            }
        }
        $app   = $manifest['themes/default/src/app/main.ts']       ?? null;
        $theme = $manifest['themes/default/src/app/theme-init.ts'] ?? null;
        if (!is_array($app) || !is_array($theme) || !is_string($app['file'] ?? null) || !is_string($theme['file'] ?? null) || !is_array($app['css'] ?? null)
                            || !array_is_list($app['css']) || !array_all($app['css'], static fn(mixed $value): bool => is_string($value))) {
            throw new AssetManifestException('Frontend manifest entries are invalid.');
        }
        return ['app' => $app['file'], 'theme' => $theme['file'], 'css' => $app['css']];
    }

    /** @return array{array<string,mixed>, array<string,mixed>} */
    private function activated(): array
    {
        $marker = @file_get_contents($this->root . '/build/asset-activation.json');
        $data   = is_string($marker) ? json_decode($marker, true) : null;
        $id     = is_array($data) && ($data['schema'] ?? null) === 1 ? ($data['id'] ?? null) : null;
        if (!is_string($id) || preg_match('/\A[0-9a-f]{64}\z/D', $id) !== 1) {
            throw new AssetManifestException('Frontend activation record is missing or invalid.');
        }
        $record = @file_get_contents($this->root . '/httpdocs/assets/.releases/' . $id . '.json');
        if (!is_string($record) || !hash_equals($id, hash('sha256', $record))) {
            throw new AssetManifestException('Frontend activation record is inconsistent.');
        }
        $data        = json_decode($record, true);
        $manifestRaw = @file_get_contents($this->root . '/build/asset-manifest.json');
        $releaseRaw  = @file_get_contents($this->root . '/build/asset-release.json');
        if (!is_array($data) || ($data['schema'] ?? null)    !== 1 || !is_string($manifestRaw) || !is_string($releaseRaw)
                             || ($data['manifest'] ?? null)  !== $manifestRaw || ($data['release'] ?? null) !== $releaseRaw
                             || ($data['phpSource'] ?? null) !== $this->phpSourceDigest()) {
            throw new AssetManifestException('Frontend activation does not match release metadata.');
        }
        $manifest = json_decode($manifestRaw, true);
        $release  = json_decode($releaseRaw, true);
        if (!is_array($manifest) || !is_array($release) || !is_array($release['assets'] ?? null) || !is_array($release['sources'] ?? null)
                                 || ($release['manifestSha256'] ?? null) !== hash('sha256', $manifestRaw)) {
            throw new AssetManifestException('Frontend release metadata is invalid.');
        }
        return [$manifest, $release];
    }

    /** @return string healthy, degraded, or broken */
    public function status(): string
    {
        try {
            $this->resolve();
            [, $release] = $this->activated();
            $this->assertCompatible($release['assets']);
            $degraded = false;
            foreach ($release['assets'] as $name => $hash) {
                if (!is_string($name) || !is_string($hash) || !$this->matchesHash($this->root . '/httpdocs/assets/' . $name, $hash)) {
                    if (is_string($name) && preg_match('/\.(br|zst)\z/D', $name)) {
                        $degraded = true;
                        error_log('TowerDNS compressed asset unavailable or damaged: ' . $name);
                        continue;
                    }
                    throw new AssetManifestException('Frontend original asset is unavailable or damaged.');
                }
            }
            return $degraded ? 'degraded' : 'healthy';
        } catch (\Throwable $exception) {
            error_log('TowerDNS frontend integrity failed: ' . $exception->getMessage());
            return 'broken';
        }
    }

    /** @param array<string, mixed> $inventory */
    private function assertCompatible(array $inventory): void
    {
        $dir     = $this->root . '/httpdocs/assets/.releases';
        $records = glob($dir . '/*.json');
        foreach ($records === false ? [] : $records as $existingPath) {
            $existing   = @file_get_contents($existingPath);
            $existingId = basename($existingPath, '.json');
            if (!is_string($existing) || preg_match('/\A[0-9a-f]{64}\z/D', $existingId) !== 1
                                      || !hash_equals($existingId, hash('sha256', $existing))) {
                throw new AssetManifestException('Existing frontend release record is inconsistent.');
            }
            $existingRecord  = json_decode($existing, true);
            $existingRelease = is_array($existingRecord) && ($existingRecord['schema'] ?? null) === 1 && is_string($existingRecord['release'] ?? null)
                ? json_decode($existingRecord['release'], true) : null;
            if (!is_array($existingRelease) || !is_array($existingRelease['assets'] ?? null)) {
                throw new AssetManifestException('Existing frontend release record is invalid.');
            }
            foreach ($inventory as $name => $hash) {
                if (isset($existingRelease['assets'][$name]) && $existingRelease['assets'][$name] !== $hash) {
                    throw new AssetManifestException('Conflicting frontend asset in existing release: ' . $name);
                }
            }
        }
    }

    private function phpSourceDigest(): string
    {
        return hash('sha256', json_encode($this->phpSources(), JSON_THROW_ON_ERROR));
    }

    /** @return array<string, string> */
    private function phpSources(): array
    {
        $paths = [];
        foreach (['src', 'httpdocs', 'install'] as $dir) {
            $base = $this->root . '/' . $dir;
            if (!is_dir($base)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $paths[] = $file->getPathname();
                }
            }
        }
        sort($paths);
        $hashes = [];
        foreach ($paths as $path) {
            $hash = @hash_file('sha256', $path);
            if (!is_string($hash)) {
                throw new AssetManifestException('Cannot read PHP release source.');
            }
            $hashes[substr($path, strlen($this->root) + 1)] = $hash;
        }
        return $hashes;
    }

    private function atomicWrite(string $path, string $bytes): void
    {
        $temp = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        if (file_put_contents($temp, $bytes, LOCK_EX) !== strlen($bytes) || !rename($temp, $path)) {
            @unlink($temp);
            throw new AssetManifestException('Could not publish frontend activation record.');
        }
    }

    private function matchesHash(string $path, string $expected): bool
    {
        $actual = is_file($path) ? @hash_file('sha256', $path) : false;
        return is_string($actual) && preg_match('/\A[0-9a-f]{64}\z/D', $expected) === 1 && hash_equals($expected, $actual);
    }
}

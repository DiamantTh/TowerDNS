<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Build a self-contained production archive.
 *
 * The release contains the public document root, private application code,
 * production Composer dependencies and pre-built frontend assets. Composer
 * and Node.js are only needed on the build machine, never on the target host.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command must run in CLI mode.\n");
    exit(1);
}

$root = dirname(__DIR__);
$version = trim((string) (getenv('TOWERDNS_VERSION') ?: ''));
$output = $root . '/dist';
$ignorePlatform = [];

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--version=')) {
        $version = substr($argument, 10);
    } elseif (str_starts_with($argument, '--output=')) {
        $output = rtrim(substr($argument, 9), '/');
    } elseif (str_starts_with($argument, '--ignore-platform-req=')) {
        $ignorePlatform[] = substr($argument, strlen('--ignore-platform-req='));
    } elseif ($argument === '--help' || $argument === '-h') {
        echo "Usage: php bin/build-release.php [--version=X.Y.Z] [--output=DIR]\n";
        echo "       [--ignore-platform-req=EXT] (local build diagnostics only)\n";
        exit(0);
    } else {
        fwrite(STDERR, "Unknown argument: {$argument}\n");
        exit(1);
    }
}

if ($version === '') {
    $versionFile = $root . '/VERSION';
    $version = is_file($versionFile) ? trim((string) file_get_contents($versionFile)) : '';
}
if (!preg_match('/^v?\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version)) {
    fwrite(STDERR, "A semantic version is required (use VERSION or --version).\n");
    exit(1);
}
$version = ltrim($version, 'v');

$stageBase = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . '/towerdns-release-' . bin2hex(random_bytes(8));
$stage = $stageBase . '/towerdns-' . $version;
$archiveBase = $output . '/towerdns-' . $version;
$composer = PHP_OS_FAMILY === 'Windows' ? 'composer.bat' : 'composer';

/** @param string $path */
$removeTree = static function (string $path) use (&$removeTree): void {
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_link($path) || is_file($path)) {
        unlink($path);
        return;
    }
    $iterator = new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS);
    foreach ($iterator as $item) {
        $removeTree($item->getPathname());
    }
    rmdir($path);
};

/** @param string $source @param string $destination */
$copyTree = static function (string $source, string $destination) use (&$copyTree): void {
    if (is_file($source)) {
        if (!is_dir(dirname($destination))) {
            mkdir(dirname($destination), 0o750, true);
        }
        copy($source, $destination);
        return;
    }
    if (!is_dir($destination)) {
        mkdir($destination, 0o750, true);
    }
    foreach (new FilesystemIterator($source, FilesystemIterator::SKIP_DOTS) as $item) {
        $copyTree($item->getPathname(), $destination . '/' . $item->getFilename());
    }
};

$copyPaths = [
    'httpdocs', 'install', 'src', 'modules', 'templates', 'themes', 'translations',
    'bin/towerdns', 'LICENSE', 'VERSION', 'composer.json', 'composer.lock',
    'docs/INSTALLATION.md',
];

try {
    mkdir($stage, 0o750, true);
    foreach ($copyPaths as $relative) {
        $source = $root . '/' . $relative;
        if (!file_exists($source)) {
            throw new RuntimeException("Required release path is missing: {$relative}");
        }
        $copyTree($source, $stage . '/' . $relative);
    }

    // Theme source is for development only; built assets are already tracked
    // under httpdocs/assets and are the only frontend runtime requirement.
    foreach (glob($stage . '/themes/*/src', GLOB_ONLYDIR) ?: [] as $themeSource) {
        $removeTree($themeSource);
    }
    foreach (glob($stage . '/modules/*/tests', GLOB_ONLYDIR) ?: [] as $moduleTests) {
        $removeTree($moduleTests);
    }
    foreach (['configs', 'cache', 'data', 'logs', 'tests', 'node_modules', '.git'] as $forbidden) {
        $removeTree($stage . '/' . $forbidden);
    }

    $composerArgs = [
        $composer,
        'install',
        '--no-dev',
        '--prefer-dist',
        '--no-interaction',
        '--no-progress',
        '--optimize-autoloader',
    ];
    foreach ($ignorePlatform as $requirement) {
        $composerArgs[] = '--ignore-platform-req=' . $requirement;
    }
    $command = implode(' ', array_map('escapeshellarg', $composerArgs));
    $command .= ' --working-dir=' . escapeshellarg($stage);
    passthru($command, $exitCode);
    if ($exitCode !== 0) {
        throw new RuntimeException('Composer production install failed.');
    }

    foreach (['composer.json', 'composer.lock', 'install/inc/phpstan-globals.php'] as $privateBuildFile) {
        $removeTree($stage . '/' . $privateBuildFile);
    }
    foreach (['install/.lock', 'install/.install_token'] as $runtimeInstallerFile) {
        $removeTree($stage . '/' . $runtimeInstallerFile);
    }
    foreach (['.git', '.env', 'node_modules', 'tests', 'configs', 'cache', 'data', 'logs', 'package.json', 'package-lock.json', 'composer.json', 'composer.lock'] as $forbidden) {
        if (file_exists($stage . '/' . $forbidden)) {
            throw new RuntimeException("Release validation failed: forbidden path {$forbidden} is present.");
        }
    }
    if (!is_dir($output) && !mkdir($output, 0o750, true) && !is_dir($output)) {
        throw new RuntimeException("Could not create release output directory: {$output}");
    }
    $archive = $archiveBase . '.zip';
    @unlink($archive);
    $phar = new PharData($archive);
    $phar->buildFromDirectory($stageBase);
    unset($phar);

    if (!is_file($archive) || !is_file($stage . '/vendor/autoload.php') || !is_file($stage . '/httpdocs/index.php')) {
        throw new RuntimeException('Release validation failed: required runtime files are missing.');
    }
    echo "Release written to {$archive}\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
} finally {
    $removeTree($stageBase);
}

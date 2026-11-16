<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * TowerDNS Installer — Helper-Funktionen
 */

/** HTML-escaping */
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Verzeichnis rekursiv löschen */
function rmDirRecursive(string $dir): bool
{
    if (!is_dir($dir)) {
        return false;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($items as $item) {
        assert($item instanceof SplFileInfo);
        if ($item->isDir()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }

    return rmdir($dir);
}

/** Verfügbare und valide Themes aus dem themes-Verzeichnis ermitteln. */
function getAvailableThemes(): array
{
    $themesDir = PROJECT_ROOT . '/themes';
    if (!is_dir($themesDir)) {
        return ['default'];
    }

    $themes = [];
    foreach (new DirectoryIterator($themesDir) as $entry) {
        if (!$entry->isDir() || $entry->isDot()) {
            continue;
        }

        $name = $entry->getFilename();
        if (preg_match('/\A[a-z0-9][a-z0-9_-]{0,63}\z/', $name) !== 1) {
            continue;
        }

        $manifest = $entry->getPathname() . '/theme.json';
        $raw      = is_file($manifest) ? file_get_contents($manifest) : false;
        /** @var mixed $meta */
        $meta = is_string($raw) ? json_decode($raw, true) : null;

        if (is_array($meta)
            && isset($meta['skeleton_theme'])
            && is_string($meta['skeleton_theme'])
            && preg_match('/\A[a-z0-9][a-z0-9_-]{0,63}\z/', $meta['skeleton_theme']) === 1
        ) {
            $themes[] = $name;
        }
    }

    sort($themes);
    return $themes ?: ['default'];
}

/**
 * Gibt die Installations-Anforderungen zurück.
 *
 * @return array<int, array{label: string, required: bool, ok: bool, detail: string}>
 */
function getRequirements(): array
{
    $reqs = [];

    // PHP-Version
    $phpOk  = version_compare(PHP_VERSION, '8.4.3', '>=');
    $reqs[] = [
        'label'    => t('req.php'),
        'required' => true,
        'ok'       => $phpOk,
        'detail'   => sprintf(t('req.php_detail'), PHP_VERSION),
    ];

    require_once PROJECT_ROOT . '/src/Infrastructure/Installation/DocumentRootPolicy.php';
    $documentRootOk = TowerDNS\Infrastructure\Installation\DocumentRootPolicy::matches(
        PROJECT_ROOT,
        isset($_SERVER['DOCUMENT_ROOT']) ? (string) $_SERVER['DOCUMENT_ROOT'] : null,
    );
    $reqs[] = [
        'label'    => t('req.document_root'),
        'required' => true,
        'ok'       => $documentRootOk,
        'detail'   => $documentRootOk ? t('req.document_root_ok') : t('req.document_root_invalid'),
    ];

    $assetStatus = (string) ($GLOBALS['_installer_assets_status'] ?? 'broken');
    $assetsOk    = $assetStatus === 'healthy' || $assetStatus === 'degraded';
    $reqs[]      = [
        'label'    => t('req.frontend_assets'),
        'required' => true,
        'ok'       => $assetsOk,
        'detail'   => match ($assetStatus) {
            'healthy'  => t('req.frontend_assets_ok'),
            'degraded' => t('req.frontend_assets_degraded'),
            default    => t('req.frontend_assets_broken'),
        },
    ];

    // Vendor-Verzeichnis
    $reqs[] = [
        'label'    => 'Composer vendor/',
        'required' => true,
        'ok'       => VENDOR_OK,
        'detail'   => VENDOR_OK ? t('req.loaded') : t('req.missing'),
    ];

    // Pflicht-Erweiterungen
    $extensions = [
        'pdo'        => true,
        'pdo_mysql'  => false,
        'pdo_pgsql'  => false,
        'pdo_sqlite' => false,
        'openssl'    => true,
        'sodium'     => true,
        'mbstring'   => true,
        'intl'       => true,
        'json'       => true,
    ];

    foreach ($extensions as $ext => $required) {
        $loaded = extension_loaded($ext);
        $reqs[] = [
            'label'    => sprintf(t('req.ext'), $ext),
            'required' => $required,
            'ok'       => $loaded,
            'detail'   => $loaded ? t('req.loaded') : t('req.missing'),
        ];
    }

    foreach (['configs', 'cache', 'data', 'logs', 'install'] as $name) {
        $path     = PROJECT_ROOT . '/' . $name;
        $writable = is_dir($path) ? is_writable($path) : is_writable(dirname($path));
        $reqs[]   = [
            'label'    => $name === 'configs' ? t('req.config_writable') : sprintf(t('req.runtime_writable'), $name),
            'required' => true,
            'ok'       => $writable,
            'detail'   => $writable ? t('req.ok') : t('req.not_writable'),
        ];
    }

    return $reqs;
}

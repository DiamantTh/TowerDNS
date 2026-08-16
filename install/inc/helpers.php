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
    $phpOk  = version_compare(PHP_VERSION, '8.4.0', '>=');
    $reqs[] = [
        'label'    => t('req.php'),
        'required' => true,
        'ok'       => $phpOk,
        'detail'   => sprintf(t('req.php_detail'), PHP_VERSION),
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

    // configs/ beschreibbar
    $configDir = PROJECT_ROOT . '/configs';
    $writable  = is_dir($configDir) ? is_writable($configDir) : is_writable(dirname($configDir));
    $reqs[]    = [
        'label'    => t('req.config_writable'),
        'required' => true,
        'ok'       => $writable,
        'detail'   => $writable ? t('req.ok') : t('req.not_writable'),
    ];

    return $reqs;
}

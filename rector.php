<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * TowerDNS — Rector-Konfiguration
 *
 * Erzwingt PHP 8.4-Features und Code-Qualitätsregeln.
 * Ausführen: vendor/bin/rector process
 * Nur prüfen: vendor/bin/rector process --dry-run
 */

use Rector\CodeQuality\Rector\Class_\InlineConstructorDefaultToPropertyRector;
use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Set\ValueObject\SetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])

    // PHP 8.4 als Ziel setzen
    ->withPhpSets(php84: true)

    // Code-Qualität und Dead-Code-Entfernung
    ->withSets([
        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
        SetList::EARLY_RETURN,
        SetList::TYPE_DECLARATION,
    ])

    // Einzelne Regeln
    ->withRules([
        InlineConstructorDefaultToPropertyRector::class,
    ])

    // Vendor und generierte Dateien ausschließen
    ->withSkip([
        __DIR__ . '/vendor',
        __DIR__ . '/install',
    ]);

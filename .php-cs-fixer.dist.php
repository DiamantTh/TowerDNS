<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * TowerDNS — PHP-CS-Fixer-Konfiguration
 *
 * Erzwingt PER-CS 2.0 (Nachfolger von PSR-12) + PHP 8.x spezifische Regeln.
 * Ausführen: vendor/bin/php-cs-fixer fix
 * Nur prüfen: vendor/bin/php-cs-fixer fix --dry-run --diff
 */

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __DIR__ . '/install/inc',
        __DIR__ . '/install/lang',
    ])
    ->name('*.php')
    ->notPath('phpstan-globals.php')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setFinder($finder)
    ->setRules([

        // ── Basis: PER-CS 2.0 (umfasst PSR-1, PSR-2, PSR-12) ────────────
        '@PER-CS2.0'        => true,
        '@PER-CS2.0:risky'  => true,

        // ── PHP 8.x Features ──────────────────────────────────────────────
        '@PHP84Migration' => true,

        // ── Strict-Types überall ──────────────────────────────────────────
        'declare_strict_types' => true,

        // ── Imports ───────────────────────────────────────────────────────
        'ordered_imports'           => ['sort_algorithm' => 'alpha'],
        'no_unused_imports'         => true,
        'global_namespace_import'   => [
            'import_classes'   => false,
            'import_constants' => false,
            'import_functions' => false,
        ],
        'fully_qualified_strict_types' => true,

        // ── Strings ───────────────────────────────────────────────────────
        'single_quote'         => true,
        'explicit_string_variable' => true,

        // ── Arrays ────────────────────────────────────────────────────────
        'array_syntax'            => ['syntax' => 'short'],
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'match', 'parameters']],
        'no_multiline_whitespace_around_double_arrow' => true,

        // ── Typen / Casts ─────────────────────────────────────────────────
        'cast_spaces'               => ['space' => 'single'],
        'modernize_types_casting'   => true,

        // ── Null-safe / moderne Operatoren ────────────────────────────────
        'modernize_strpos' => true,

        // ── Leerzeilen / Leerzeichen ──────────────────────────────────────
        'no_extra_blank_lines'  => ['tokens' => ['curly_brace_block', 'extra', 'parenthesis_brace_block', 'return', 'square_brace_block', 'throw', 'use']],
        'no_spaces_around_offset' => true,

        // ── Kommentare ────────────────────────────────────────────────────
        'no_empty_comment'  => true,
        'multiline_comment_opening_closing' => true,

        // ── Return-Types ──────────────────────────────────────────────────
        'void_return' => true,

        // ── Misc ──────────────────────────────────────────────────────────
        'no_useless_else'     => true,
        'no_useless_return'   => true,
        'yoda_style'          => false,
        'concat_space'        => ['spacing' => 'one'],
        'binary_operator_spaces' => ['default' => 'align_single_space_minimal'],
    ]);

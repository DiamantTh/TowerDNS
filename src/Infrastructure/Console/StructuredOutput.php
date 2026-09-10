<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Console;

use Symfony\Component\Console\Style\SymfonyStyle;

/** Renders normalized CLI data for humans and automation. */
final class StructuredOutput
{
    /**
     * @param array<string, string> $tableColumns Header => row field
     * @param list<array<string, mixed>> $rows
     */
    public static function write(
        SymfonyStyle $io,
        string $format,
        array $tableColumns,
        array $rows,
        string $collectionName,
    ): void {
        match ($format) {
            'json'  => $io->writeln(json_encode([$collectionName => $rows], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)),
            'toml'  => $io->write(toml_encode([$collectionName => $rows])),
            default => $io->table(
                array_keys($tableColumns),
                array_map(
                    static fn(array $row): array => array_map(
                        static fn(string $field): string => self::stringify($row[$field] ?? null),
                        $tableColumns,
                    ),
                    $rows,
                ),
            ),
        };
    }

    private static function stringify(mixed $value): string
    {
        return match (true) {
            $value === null   => '',
            $value === true   => 'yes',
            $value === false  => 'no',
            is_scalar($value) => (string) $value,
            default           => json_encode($value, JSON_THROW_ON_ERROR),
        };
    }
}

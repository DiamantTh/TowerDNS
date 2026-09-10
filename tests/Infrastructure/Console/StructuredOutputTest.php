<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Console;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use TowerDNS\Infrastructure\Console\StructuredOutput;

final class StructuredOutputTest extends TestCase
{
    public function testWritesMachineReadableJsonAndToml(): void
    {
        $rows = [[
            'id'     => 'zone-1',
            'name'   => 'example.org',
            'active' => true,
        ]];

        $jsonOutput = new BufferedOutput();
        StructuredOutput::write(
            new SymfonyStyle(new ArrayInput([]), $jsonOutput),
            'json',
            ['Zone' => 'name'],
            $rows,
            'zones',
        );

        self::assertSame($rows, json_decode($jsonOutput->fetch(), true, flags: JSON_THROW_ON_ERROR)['zones']);

        $tomlOutput = new BufferedOutput();
        StructuredOutput::write(
            new SymfonyStyle(new ArrayInput([]), $tomlOutput),
            'toml',
            ['Zone' => 'name'],
            $rows,
            'zones',
        );

        self::assertStringContainsString('name = "example.org"', $tomlOutput->fetch());
    }
}

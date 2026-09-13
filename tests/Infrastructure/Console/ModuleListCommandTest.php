<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Console;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use TowerDNS\Application\Module\LocalModuleDiscovery;
use TowerDNS\Infrastructure\Console\ModuleListCommand;

final class ModuleListCommandTest extends TestCase
{
    public function testJsonFormatContainsDiscoveredModules(): void
    {
        $command = new ModuleListCommand(new LocalModuleDiscovery(dirname(__DIR__, 3) . '/modules'));
        $tester  = new CommandTester($command);

        self::assertSame(0, $tester->execute(['--format' => 'json']));
        $data = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('modules', $data);
        self::assertNotEmpty($data['modules']);
        self::assertArrayHasKey('id', $data['modules'][0]);
    }

    public function testTomlFormatContainsDiscoveredModules(): void
    {
        $command = new ModuleListCommand(new LocalModuleDiscovery(dirname(__DIR__, 3) . '/modules'));
        $tester  = new CommandTester($command);

        self::assertSame(0, $tester->execute(['--format' => 'toml']));
        self::assertStringContainsString('modules', $tester->getDisplay());
        self::assertStringContainsString('towerdns.', $tester->getDisplay());
    }
}

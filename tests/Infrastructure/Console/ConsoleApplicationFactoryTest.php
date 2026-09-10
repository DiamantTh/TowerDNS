<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Console;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\ApplicationTester;
use TowerDNS\Application\ContainerFactory;
use TowerDNS\Infrastructure\Console\CommandListCommand;
use TowerDNS\Infrastructure\Console\ConsoleApplicationFactory;
use TowerDNS\Infrastructure\Console\InstallCommand;
use TowerDNS\Infrastructure\Console\PasswordResetCommand;

final class ConsoleApplicationFactoryTest extends TestCase
{
    public function testRegistersThePublicCommands(): void
    {
        $container   = ContainerFactory::create(dirname(__DIR__, 3));
        $application = ConsoleApplicationFactory::create($container);

        self::assertTrue($application->has('towerdns:install'));
        self::assertTrue($application->has('towerdns:user:password-reset'));
        self::assertTrue($application->has('zone:list'));
        self::assertTrue($application->has('record:list'));
        self::assertInstanceOf(CommandListCommand::class, $application->get('list'));
        self::assertSame($container->get(InstallCommand::class), $application->get('towerdns:install'));
        self::assertSame($container->get(PasswordResetCommand::class), $application->get('towerdns:user:password-reset'));
        self::assertNull($application->getDefinition()->getOption('verbose')->getShortcut());
        self::assertSame('V|v', $application->getDefinition()->getOption('version')->getShortcut());
    }

    public function testListsCommandsAsToml(): void
    {
        $application = ConsoleApplicationFactory::create(ContainerFactory::create(dirname(__DIR__, 3)));
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);

        self::assertSame(0, $tester->run(['command' => 'list', '--format' => 'toml']));
        self::assertStringContainsString('name = "TowerDNS"', $tester->getDisplay());
        self::assertStringContainsString('name = "towerdns:install"', $tester->getDisplay());
    }
}

<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Console;

use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\LazyCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

/**
 * Builds the public TowerDNS command-line application from the DI container.
 *
 * Commands remain explicitly registered, while their dependencies are resolved
 * consistently with the HTTP application.
 */
final class ConsoleApplicationFactory
{
    public static function create(ContainerInterface $container): Application
    {
        $application = new Application('TowerDNS', '1.0.0');
        $application->setDefinition(new InputDefinition([
            new InputArgument('command', InputArgument::REQUIRED, 'The command to execute'),
            new InputOption('help', 'h', InputOption::VALUE_NONE, 'Display help for the given command'),
            new InputOption('silent', null, InputOption::VALUE_NONE, 'Do not output any message'),
            new InputOption('quiet', 'q', InputOption::VALUE_NONE, 'Only errors are displayed. All other output is suppressed'),
            new InputOption('verbose', null, InputOption::VALUE_NONE, 'Increase the verbosity of messages'),
            new InputOption('version', 'V|v', InputOption::VALUE_NONE, 'Display this application version'),
            new InputOption('ansi', null, InputOption::VALUE_NEGATABLE, 'Force (or disable --no-ansi) ANSI output'),
            new InputOption('no-interaction', 'n', InputOption::VALUE_NONE, 'Do not ask any interactive question'),
        ]));
        $application->addCommand(new CommandListCommand());
        $application->addCommand($container->get(InstallCommand::class));
        $application->addCommand($container->get(PasswordResetCommand::class));
        $application->addCommand($container->get(ZoneListCommand::class));
        $application->addCommand($container->get(RecordListCommand::class));
        $application->addCommand($container->get(RrsetListCommand::class));
        $application->addCommand($container->get(ModuleListCommand::class));
        $application->addCommand(new LazyCommand(
            'towerdns:schema:status',
            [],
            'Show TowerDNS database migration status',
            false,
            static fn(): SchemaStatusCommand => $container->get(SchemaStatusCommand::class),
        ));
        $application->addCommand(new LazyCommand(
            'towerdns:schema:migrate',
            [],
            'Apply pending TowerDNS database migrations',
            false,
            static fn(): SchemaMigrateCommand => $container->get(SchemaMigrateCommand::class),
        ));
        $application->addCommand(new LazyCommand(
            'towerdns:schema:validate',
            [],
            'Validate the TowerDNS database schema',
            false,
            static fn(): SchemaValidateCommand => $container->get(SchemaValidateCommand::class),
        ));
        $application->setDefaultCommand('towerdns:install');

        return $application;
    }
}

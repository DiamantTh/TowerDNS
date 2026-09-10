<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Console;

use Symfony\Component\Console\Command\ListCommand;
use Symfony\Component\Console\Descriptor\ApplicationDescription;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Adds TOML output to Symfony's built-in command list formats. */
final class CommandListCommand extends ListCommand
{
    #[\Override]
    protected function configure(): void
    {
        $this
            ->setName('list')
            ->setDefinition([
                new InputArgument('namespace', InputArgument::OPTIONAL, 'The namespace name'),
                new InputOption('raw', null, InputOption::VALUE_NONE, 'Output only command names'),
                new InputOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: txt, xml, json, md, or toml', 'txt', ['txt', 'xml', 'json', 'md', 'toml']),
                new InputOption('short', null, InputOption::VALUE_NONE, 'Skip command argument descriptions'),
            ])
            ->setDescription('List commands');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('format') !== 'toml') {
            return parent::execute($input, $output);
        }

        $application = $this->getApplication();
        if (!$application instanceof \Symfony\Component\Console\Application) {
            $output->writeln('<error>The command list is not attached to an application.</error>');

            return self::FAILURE;
        }

        $description = new ApplicationDescription($application, $input->getArgument('namespace'));
        $commands    = [];

        foreach ($description->getCommands() as $command) {
            $commands[] = [
                'name'        => $command->getName(),
                'description' => $command->getDescription(),
                'aliases'     => $command->getAliases(),
            ];
        }

        if ($input->getOption('raw')) {
            $output->writeln(implode("\n", array_column($commands, 'name')));

            return self::SUCCESS;
        }

        $output->write(toml_encode([
            'application' => [
                'name'    => $application->getName(),
                'version' => $application->getVersion(),
            ],
            'commands' => $commands,
        ]));

        return self::SUCCESS;
    }
}

<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TowerDNS\Application\Module\LocalModuleDiscovery;

final class ModuleListCommand extends Command
{
    public function __construct(private readonly LocalModuleDiscovery $discovery)
    {
        parent::__construct('module:list');
    }

    protected function configure(): void
    {
        $this->setDescription('List locally discovered TowerDNS modules.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach ($this->discovery->discover() as $module) {
            $output->writeln(sprintf("%s\t%s\t%s\t%s", $module->id, $module->type->value, $module->version, $module->displayName));
        }
        return self::SUCCESS;
    }
}

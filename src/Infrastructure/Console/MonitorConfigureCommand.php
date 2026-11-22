<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TowerDNS\Application\Services\MonitoringService;

/** @psalm-api Discovered by Symfony Console. */
#[AsCommand(name: 'towerdns:monitor:configure', description: 'Enable or configure read-only monitoring for a managed zone')]
final class MonitorConfigureCommand extends Command
{
    public function __construct(private readonly MonitoringService $monitoring)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('zone-id', InputArgument::REQUIRED, 'Managed zone ID');
        $this->addOption('enable', null, InputOption::VALUE_NONE, 'Enable monitoring');
        $this->addOption('disable', null, InputOption::VALUE_NONE, 'Disable monitoring');
        $this->addOption('smtp-dane', null, InputOption::VALUE_NONE, 'Enable DNS-only SMTP-DANE checks using the configured validating resolver');
        $this->addOption('interval', null, InputOption::VALUE_REQUIRED, 'Check interval in minutes (5–1440)', '60');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $id       = (string) $input->getArgument('zone-id');
        $interval = (string) $input->getOption('interval');
        if (preg_match('/\A[1-9][0-9]{0,8}\z/D', $id) !== 1 || preg_match('/\A[0-9]{1,4}\z/D', $interval) !== 1 || (bool) $input->getOption('enable') === (bool) $input->getOption('disable')) {
            $io->error('Provide a valid zone ID, interval and exactly one of --enable or --disable.');
            return self::INVALID;
        }
        try {
            $this->monitoring->configure((int) $id, (bool) $input->getOption('enable'), (bool) $input->getOption('smtp-dane'), (int) $interval, null);
        } catch (\InvalidArgumentException $exception) {
            $io->error($exception->getMessage());
            return self::INVALID;
        }
        $io->success(sprintf('Monitoring configuration saved for managed zone %s. DNS records were not changed.', $id));
        return self::SUCCESS;
    }
}

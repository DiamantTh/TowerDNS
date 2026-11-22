<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TowerDNS\Application\Services\MonitoringService;

/** @psalm-api Discovered by Symfony Console. */
#[AsCommand(name: 'towerdns:monitor:run', description: 'Run due, explicitly enabled read-only DNS monitoring checks')]
final class MonitorRunCommand extends Command
{
    public function __construct(private readonly MonitoringService $monitoring)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('zone', null, InputOption::VALUE_REQUIRED, 'Run the enabled check for one managed zone ID');
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Ignore the configured interval for due enabled checks');
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: table or json', 'table', ['table', 'json']);
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $zoneId = $input->getOption('zone');
        if ($zoneId !== null && (!is_string($zoneId) || preg_match('/\A[1-9][0-9]{0,8}\z/D', $zoneId) !== 1)) {
            new SymfonyStyle($input, $output)->error('Zone must be a positive managed-zone ID.');
            return self::INVALID;
        }
        $result = $this->monitoring->runDue($zoneId === null ? null : (int) $zoneId, (bool) $input->getOption('force'));
        if ((string) $input->getOption('format') === 'json') {
            $output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $io = new SymfonyStyle($input, $output);
            if ($result['results'] === []) {
                $io->text('No enabled DNS monitoring checks are due.');
            } else {
                $io->table(['Zone', 'Check', 'Status', 'Severity', 'Changed', 'Checked at'], array_map(
                    static fn(array $row): array => [$row['zone'], $row['check_type'], $row['status'], $row['severity'], $row['changed'] ? 'yes' : 'no', $row['checked_at']],
                    $result['results'],
                ));
            }
            $io->text(sprintf('Checked %d; state transitions %d; warnings %d; execution failures %d.', $result['checked'], $result['changed'], $result['warnings'], $result['failures']));
        }
        return $result['failures'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}

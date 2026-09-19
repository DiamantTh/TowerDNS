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
use TowerDNS\Infrastructure\Persistence\SchemaMigrationManager;

#[AsCommand(name: 'towerdns:schema:migrate', description: 'Apply pending TowerDNS database migrations')]
final class SchemaMigrateCommand extends Command
{
    public function __construct(private readonly SchemaMigrationManager $migrations)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('confirm', null, InputOption::VALUE_NONE, 'Confirm the backup and migration operation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->caution('Schema changes can have database-specific rollback limits. Create and verify a backup first.');

        $confirmed = (bool) $input->getOption('confirm');
        if (!$confirmed && $input->isInteractive()) {
            $confirmed = $io->confirm('Continue with the pending schema migrations?', false);
        }
        if (!$confirmed) {
            if (!$input->isInteractive()) {
                $io->error('Non-interactive migrations require --confirm after a verified backup.');
                return self::FAILURE;
            }
            $io->warning('Migration cancelled.');
            return self::SUCCESS;
        }

        try {
            $status = $this->migrations->migrate();
        } catch (\Throwable) {
            $io->error('The schema update failed. Check the database connection, permissions and the backup before retrying.');
            return self::FAILURE;
        }

        if ($status->pending === []) {
            $io->success('Database schema is current.');
            return self::SUCCESS;
        }

        $io->success('Database schema migration completed.');
        return self::SUCCESS;
    }
}

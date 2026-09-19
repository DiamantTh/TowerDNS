<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TowerDNS\Infrastructure\Persistence\SchemaMigrationManager;

#[AsCommand(name: 'towerdns:schema:validate', description: 'Validate the TowerDNS database schema')]
final class SchemaValidateCommand extends Command
{
    public function __construct(private readonly SchemaMigrationManager $migrations)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $status = $this->migrations->status();
        } catch (\Throwable) {
            $io->error('The database schema could not be inspected. Check the database connection and permissions.');
            return self::FAILURE;
        }

        if (!$status->schemaCurrent || $status->dataIssues !== []) {
            $io->error('The database schema is incomplete or incompatible.');
            $io->listing($status->schemaIssues);
            $io->listing($status->dataIssues);
            return self::FAILURE;
        }

        $io->success('The database schema is structurally valid.');
        return self::SUCCESS;
    }
}

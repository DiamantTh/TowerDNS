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

#[AsCommand(name: 'towerdns:schema:status', description: 'Show TowerDNS database migration status')]
final class SchemaStatusCommand extends Command
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

        $io->title('TowerDNS database schema');
        $io->definitionList(
            ['Migration metadata' => $status->metadataInitialized ? 'initialized' : 'not initialized'],
            ['Schema' => $status->schemaCurrent ? 'current' : 'requires attention'],
            ['Pending migrations' => (string) count($status->pending)],
        );

        if ($status->canBaseline) {
            $io->note('The existing schema can be adopted as the migration baseline.');
        }
        if ($status->schemaIssues !== []) {
            $io->section('Schema issues');
            $io->listing($status->schemaIssues);
        }
        if ($status->dataIssues !== []) {
            $io->section('Data issues');
            $io->listing($status->dataIssues);
        }
        if ($status->pending !== []) {
            $io->section('Pending migrations');
            $io->listing(array_map(static fn(array $migration): string => $migration['version'] . ' — ' . $migration['description'], $status->pending));
        }

        return self::SUCCESS;
    }
}

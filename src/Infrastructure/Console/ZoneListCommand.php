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
use TowerDNS\Application\Provider\ProviderRegistry;

#[AsCommand(name: 'zone:list', description: 'List DNS zones of a configured provider')]
final class ZoneListCommand extends Command
{
    public function __construct(private readonly ProviderRegistry $providers)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('provider', InputArgument::REQUIRED, 'Configured provider ID, e.g. desec or powerdns')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: table, json, or toml', 'table', ['table', 'json', 'toml']);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $zones = $this->providers->get((string) $input->getArgument('provider'))->listZones();
        } catch (\Throwable $exception) {
            $io->error('Could not list zones: ' . $exception->getMessage());

            return self::FAILURE;
        }

        $rows = array_map(static fn(\TowerDNS\Domain\DNS\Zone $zone): array => [
            'provider' => $zone->providerId,
            'id'       => $zone->id,
            'name'     => $zone->name,
            'active'   => $zone->active,
            'tags'     => $zone->tags,
            'metadata' => $zone->metadata,
        ], $zones);

        StructuredOutput::write(
            $io,
            (string) $input->getOption('format'),
            ['Provider' => 'provider', 'ID' => 'id', 'Zone' => 'name', 'Active' => 'active'],
            $rows,
            'zones',
        );

        return self::SUCCESS;
    }
}

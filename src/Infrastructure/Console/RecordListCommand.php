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
use TowerDNS\Domain\DNS\Record;
use TowerDNS\Domain\DNS\Zone;

#[AsCommand(name: 'record:list', description: 'List DNS records in a provider zone')]
final class RecordListCommand extends Command
{
    public function __construct(private readonly ProviderRegistry $providers)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('provider', InputArgument::REQUIRED, 'Configured provider ID, e.g. desec or powerdns')
            ->addArgument('zone', InputArgument::REQUIRED, 'Zone name, e.g. example.org')
            ->addOption('type', 't', InputOption::VALUE_REQUIRED, 'Only records with this DNS type')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: table, json, or toml', 'table', ['table', 'json', 'toml']);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io   = new SymfonyStyle($input, $output);
        $type = strtoupper((string) ($input->getOption('type') ?? ''));

        try {
            $provider = $this->providers->get((string) $input->getArgument('provider'));
            $zone     = $this->resolveZoneByName($provider->listZones(), (string) $input->getArgument('zone'));
            $records  = $provider->listRecords($zone->id);
        } catch (\Throwable $exception) {
            $io->error('Could not list records: ' . $exception->getMessage());

            return self::FAILURE;
        }

        if ($type !== '') {
            $records = array_values(array_filter(
                $records,
                static fn(Record $record): bool => $record->type->value === $type,
            ));
        }

        $rows = array_map(static fn(Record $record): array => [
            'id'       => $record->id,
            'zone_id'  => $record->zoneId,
            'name'     => $record->name,
            'type'     => $record->type->value,
            'ttl'      => $record->ttl,
            'content'  => $record->content,
            'comment'  => $record->comment,
            'metadata' => $record->metadata,
        ], $records);

        StructuredOutput::write(
            $io,
            (string) $input->getOption('format'),
            ['Name' => 'name', 'Type' => 'type', 'TTL' => 'ttl', 'Content' => 'content', 'Comment' => 'comment'],
            $rows,
            'records',
        );

        return self::SUCCESS;
    }

    /**
     * @param list<Zone> $zones
     */
    private function resolveZoneByName(array $zones, string $zoneName): Zone
    {
        $normalizedName = self::normalizeZoneName($zoneName);
        $matches        = array_values(array_filter(
            $zones,
            static fn(Zone $zone): bool => self::normalizeZoneName($zone->name) === $normalizedName,
        ));

        return match (count($matches)) {
            1       => $matches[0],
            0       => throw new \InvalidArgumentException(sprintf('Zone "%s" was not found. Use zone:list to see available zone names.', $zoneName)),
            default => throw new \InvalidArgumentException(sprintf('Zone name "%s" is ambiguous for this provider.', $zoneName)),
        };
    }

    private static function normalizeZoneName(string $name): string
    {
        return strtolower(rtrim($name, '.'));
    }
}

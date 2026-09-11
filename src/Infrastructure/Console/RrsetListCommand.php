<?php

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TowerDNS\Application\Contracts\RrsetProviderInterface;
use TowerDNS\Application\Provider\ProviderRegistry;
use TowerDNS\Domain\DNS\Rrset;
use TowerDNS\Domain\DNS\Zone;

#[AsCommand(name: 'rrset:list', description: 'List DNS RRsets in a provider zone')]
final class RrsetListCommand extends Command
{
    public function __construct(private readonly ProviderRegistry $providers) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addArgument('provider', InputArgument::REQUIRED, 'Configured provider ID')
            ->addArgument('zone', InputArgument::REQUIRED, 'Zone name')
            ->addOption('type', 't', InputOption::VALUE_REQUIRED, 'Only RRsets with this DNS type')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: table, json, or toml', 'table', ['table', 'json', 'toml']);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $provider = $this->providers->get((string) $input->getArgument('provider'));
            if (!$provider instanceof RrsetProviderInterface) { throw new \LogicException('Provider unterstützt keine RRset-Operationen.'); }
            $zone = $this->resolveZone($provider->listZones(), (string) $input->getArgument('zone'));
            $sets = $provider->listRrsets($zone->id);
        } catch (\Throwable $exception) {
            $io->error('Could not list RRsets: ' . $exception->getMessage());
            return self::FAILURE;
        }
        $type = strtoupper((string) $input->getOption('type'));
        if ($type !== '') { $sets = array_values(array_filter($sets, static fn(Rrset $set): bool => $set->type->presentation === $type)); }
        $rows = array_map(static fn(Rrset $set): array => ['zone_id' => $set->zoneId, 'name' => $set->ownerName, 'type' => $set->type->presentation, 'ttl' => $set->ttl, 'rdata' => $set->rdata, 'provider_identity' => $set->providerIdentity], $sets);
        StructuredOutput::write($io, (string) $input->getOption('format'), ['Name' => 'name', 'Type' => 'type', 'TTL' => 'ttl', 'RDATA' => 'rdata'], $rows, 'rrsets');
        return self::SUCCESS;
    }

    /** @param list<Zone> $zones */
    private function resolveZone(array $zones, string $name): Zone
    {
        $name = strtolower(rtrim($name, '.'));
        $matches = array_values(array_filter($zones, static fn(Zone $zone): bool => strtolower(rtrim($zone->name, '.')) === $name));
        if (count($matches) !== 1) { throw new \InvalidArgumentException('Zone was not found or is ambiguous.'); }
        return $matches[0];
    }
}

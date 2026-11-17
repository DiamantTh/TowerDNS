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
use TowerDNS\Application\DNS\TlsaGenerator;

/** Offline TLSA generation from local public certificate files; no DNS writes. */
#[AsCommand(name: 'tlsa:generate', description: 'Generate TLSA RDATA from public PEM/DER certificates or SPKI keys')]
final class TlsaGenerateCommand extends Command
{
    public function __construct(private readonly TlsaGenerator $generator)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('file', 'f', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Local PEM/DER certificate path (repeatable)')
            ->addOption('usage', null, InputOption::VALUE_REQUIRED, 'TLSA certificate usage (0-3)', '3')
            ->addOption('selector', null, InputOption::VALUE_REQUIRED, 'TLSA selector (0-1)', '1')
            ->addOption('matching', null, InputOption::VALUE_REQUIRED, 'TLSA matching type (0-2)', '1')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output: rdata or json', 'rdata');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $files = $input->getOption('file');
        if (!is_array($files) || $files === []) {
            $output->writeln('<error>Specify at least one --file path.</error>');
            return self::INVALID;
        }
        foreach (['usage' => 3, 'selector' => 1, 'matching' => 2] as $name => $max) {
            $value = (string) $input->getOption($name);
            if (preg_match('/^[0-9]$/D', $value) !== 1 || (int) $value > $max) {
                $output->writeln('<error>Invalid --' . $name . ' value.</error>');
                return self::INVALID;
            }
        }
        $format = (string) $input->getOption('format');
        if (!in_array($format, ['rdata', 'json'], true)) {
            $output->writeln('<error>Invalid --format value.</error>');
            return self::INVALID;
        }
        try {
            $pem = '';
            foreach ($files as $file) {
                if (!is_string($file) || !is_file($file) || !is_readable($file) || filesize($file) > 750_000) {
                    throw new \InvalidArgumentException('Certificate file is unavailable or too large.');
                }
                $contents = file_get_contents($file);
                if (!is_string($contents)) {
                    throw new \InvalidArgumentException('Could not read certificate.');
                }
                if (!str_contains($contents, '-----BEGIN ')) {
                    $contents = "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($contents), 64, "\n") . "-----END CERTIFICATE-----";
                }
                $pem .= $contents . "\n";
            }
            $rdata = $this->generator->generate($pem, (int) $input->getOption('usage'), (int) $input->getOption('selector'), (int) $input->getOption('matching'));
        } catch (\InvalidArgumentException $exception) {
            $output->writeln('<error>' . htmlspecialchars($exception->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</error>');
            return self::FAILURE;
        }
        if ($format === 'json') {
            $output->writeln((string) json_encode(['rdata' => $rdata], JSON_THROW_ON_ERROR));
        } else {
            foreach ($rdata as $record) {
                $output->writeln($record);
            }
        }
        return self::SUCCESS;
    }
}

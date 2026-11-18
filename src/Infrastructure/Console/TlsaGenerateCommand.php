<?php

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TowerDNS\Application\DNS\TlsaGenerator;
use TowerDNS\Application\Validation\DNSNameValidator;

final class TlsaGenerateCommand extends Command
{
    public function __construct(private readonly TlsaGenerator $generator)
    {
        parent::__construct('tlsa:generate');
    }

    protected function configure(): void
    {
        $this->setDescription('Generate TLSA records from a local X.509 certificate or PEM chain.')
            ->addArgument('domain', InputArgument::REQUIRED, 'Service DNS name')
            ->addArgument('port', InputArgument::REQUIRED, 'Service port')
            ->addArgument('certificate', InputArgument::REQUIRED, 'PEM or DER certificate file')
            ->addOption('usage', null, InputOption::VALUE_REQUIRED, 'Certificate usage (0–3)', '3')
            ->addOption('selector', null, InputOption::VALUE_REQUIRED, 'Selector (0–1)', '1')
            ->addOption('matching', null, InputOption::VALUE_REQUIRED, 'Matching type (0–2)', '1')
            ->addOption('protocol', null, InputOption::VALUE_REQUIRED, 'Transport protocol tcp or udp', 'tcp');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $domain   = DNSNameValidator::normalise((string) $input->getArgument('domain'));
            $port     = (string) $input->getArgument('port');
            $protocol = (string) $input->getOption('protocol');
            $usage    = (string) $input->getOption('usage');
            $selector = (string) $input->getOption('selector');
            $matching = (string) $input->getOption('matching');
            if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535 || !in_array($protocol, ['tcp', 'udp'], true)
                                    || !in_array($usage, ['0', '1', '2', '3'], true) || !in_array($selector, ['0', '1'], true) || !in_array($matching, ['0', '1', '2'], true)) {
                throw new \InvalidArgumentException('Invalid port, protocol, or TLSA parameters.');
            }
            $path = (string) $input->getArgument('certificate');
            if (!is_file($path) || !is_readable($path) || filesize($path) > 1048576) {
                throw new \InvalidArgumentException('Certificate file is missing, unreadable, or too large.');
            }
            $data = file_get_contents($path);
            if ($data === false) {
                throw new \InvalidArgumentException('Cannot read certificate file.');
            }
            foreach ($this->generator->generate($data, (int) $usage, (int) $selector, (int) $matching) as $record) {
                $output->writeln(sprintf('_%d._%s.%s. IN TLSA %s', (int) $port, $protocol, $domain, $record));
            }
            return Command::SUCCESS;
        } catch (\InvalidArgumentException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }
}

<?php

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Console;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TowerDNS\Infrastructure\Backup\BackupException;
use TowerDNS\Infrastructure\Backup\DatabaseBackup;

/** @psalm-api Constructed in ConsoleApplicationFactory. */
final class DatabaseBackupCommand extends Command
{
    /** @param \Closure(): Connection $connection */
    public function __construct(
        private readonly DatabaseBackup $backup,
        private readonly \Closure $connection,
        private readonly string $operation,
    ) {
        parent::__construct('towerdns:backup:' . $operation);
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addArgument('directory', InputArgument::REQUIRED, 'Private backup directory');
        $this->setDescription(self::description($this->operation));
        if ($this->operation === 'create') {
            $this->addOption('allow-plaintext', null, InputOption::VALUE_NONE, 'Acknowledge that the dump contains sensitive data and is not encrypted');
        }
        if ($this->operation === 'restore') {
            $this->addOption('sqlite-target', null, InputOption::VALUE_REQUIRED, 'New SQLite file in an isolated private directory');
            $this->addOption('server-target', null, InputOption::VALUE_NONE, 'Use the isolated towerdns_restore_* database configured in this checkout');
            $this->addOption('confirm', null, InputOption::VALUE_REQUIRED, 'Required value: offline-restore');
        }
    }

    public static function description(string $operation): string
    {
        return match ($operation) {
            'create'  => 'Create a private database snapshot and integrity manifest',
            'verify'  => 'Verify database backup format, metadata and checksums',
            'restore' => 'Restore a verified database backup into an isolated target',
            default   => throw new \InvalidArgumentException('Unsupported backup command.'),
        };
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $directory = (string) $input->getArgument('directory');
        try {
            if ($this->operation === 'create') {
                if ($input->getOption('allow-plaintext') !== true) {
                    $output->writeln('Backup contains sensitive plaintext. Use a protected destination and explicitly pass --allow-plaintext.');
                    return self::INVALID;
                }
                $result = $this->backup->create(($this->connection)(), $directory);
            } elseif ($this->operation === 'verify') {
                $result                 = $this->backup->verify($directory);
                $result['verification'] = 'integrity_and_format';
            } else {
                $sqlite = $input->getOption('sqlite-target');
                $remote = $input->getOption('server-target') === true;
                if (is_string($sqlite) === $remote || $input->getOption('confirm') !== 'offline-restore') {
                    $output->writeln('Choose exactly one isolated restore target and pass --confirm=offline-restore.');
                    return self::INVALID;
                }
                $result = $this->backup->restore($directory, is_string($sqlite) ? $sqlite : null, $remote ? ($this->connection)() : null, 'offline-restore');
            }
            $output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
            return self::SUCCESS;
        } catch (BackupException $exception) {
            $output->writeln($exception->getMessage(), OutputInterface::OUTPUT_RAW);
            return self::FAILURE;
        } catch (\Exception) {
            $output->writeln('Backup operation failed. Check database access, exclusive maintenance, file permissions and available disk space.');
            return self::FAILURE;
        }
    }
}

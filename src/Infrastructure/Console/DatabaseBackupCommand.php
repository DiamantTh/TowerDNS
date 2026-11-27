<?php

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Console;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
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
        $this->addOption('passphrase-file', null, InputOption::VALUE_REQUIRED, 'Read a passphrase from a private owner-only file outside the checkout');
        if ($this->operation === 'create') {
            $this->addOption('allow-plaintext', null, InputOption::VALUE_NONE, 'Acknowledge that the dump contains sensitive data and is not encrypted');
            $this->addOption('encrypt', null, InputOption::VALUE_NONE, 'Encrypt the database payload with an interactively entered passphrase');
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
        $io        = new SymfonyStyle($input, $output);
        try {
            if ($this->operation === 'create') {
                $encrypt = $input->getOption('encrypt') === true;
                if ($encrypt && $input->getOption('allow-plaintext') === true) {
                    $output->writeln('Choose encrypted backup or explicit plaintext acknowledgement, not both.');
                    return self::INVALID;
                }
                if (!$encrypt && $input->getOption('allow-plaintext') !== true) {
                    $output->writeln('Backup contains sensitive plaintext. Use a protected destination and explicitly pass --allow-plaintext.');
                    return self::INVALID;
                }
                if (!$encrypt && $input->getOption('passphrase-file') !== null) {
                    $output->writeln('--passphrase-file requires --encrypt.');
                    return self::INVALID;
                }
                $passphrase = $encrypt ? $this->passphrase($input, $io, true) : null;
                $result     = $this->backup->create(($this->connection)(), $directory, $passphrase);
            } elseif ($this->operation === 'verify') {
                $encrypted = $this->backup->isEncryptedBackup($directory);
                if (!$encrypted && $input->getOption('passphrase-file') !== null) {
                    throw new BackupException('--passphrase-file is only used for encrypted backups.');
                }
                $passphrase = $encrypted ? $this->passphrase($input, $io, false) : null;
                $result     = $this->backup->verify($directory, $passphrase);
            } else {
                $sqlite = $input->getOption('sqlite-target');
                $remote = $input->getOption('server-target') === true;
                if (is_string($sqlite) === $remote || $input->getOption('confirm') !== 'offline-restore') {
                    $output->writeln('Choose exactly one isolated restore target and pass --confirm=offline-restore.');
                    return self::INVALID;
                }
                $encrypted = $this->backup->isEncryptedBackup($directory);
                if (!$encrypted && $input->getOption('passphrase-file') !== null) {
                    throw new BackupException('--passphrase-file is only used for encrypted backups.');
                }
                $passphrase = $encrypted ? $this->passphrase($input, $io, false) : null;
                $result     = $this->backup->restore($directory, is_string($sqlite) ? $sqlite : null, $remote ? ($this->connection)() : null, 'offline-restore', $passphrase);
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

    private function passphrase(InputInterface $input, SymfonyStyle $io, bool $confirm): string
    {
        $file = $input->getOption('passphrase-file');
        if (is_string($file)) {
            return $this->backup->readPassphraseFile($file);
        }
        if ($file !== null) {
            throw new BackupException('--passphrase-file must be a path.');
        }

        $first = $io->askHidden('Backup passphrase (minimum 16 bytes)');
        if (!is_string($first)) {
            throw new BackupException('A backup passphrase is required.');
        }
        if ($confirm) {
            $second = $io->askHidden('Repeat backup passphrase');
            if (!is_string($second) || !hash_equals($first, $second)) {
                throw new BackupException('Backup passphrases did not match.');
            }
        }
        return $first;
    }
}

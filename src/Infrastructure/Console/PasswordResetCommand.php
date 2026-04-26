<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Console;

use Devium\Toml\Toml;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Resets the password of a TowerDNS user from the command line.
 *
 * Usage:
 *   php install/install-cli.php towerdns:user:password-reset admin@example.com
 *   php install/install-cli.php towerdns:user:password-reset admin@example.com --generate
 *   php install/install-cli.php towerdns:user:password-reset admin@example.com --keep-api-keys
 */
#[AsCommand(
    name: 'towerdns:user:password-reset',
    description: 'Reset a TowerDNS user password via CLI',
)]
final class PasswordResetCommand extends Command
{
    public function __construct(private readonly string $projectRoot)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'E-mail address of the user')
            ->addOption('generate', 'g', InputOption::VALUE_NONE, 'Generate a secure random password')
            ->addOption('keep-api-keys', null, InputOption::VALUE_NONE, 'Keep existing API keys active (default: revoke)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email    = strtolower(trim((string) $input->getArgument('email')));
        $generate = (bool) $input->getOption('generate');
        $keepKeys = (bool) $input->getOption('keep-api-keys');

        // ── Load DB config ────────────────────────────────────────────────
        $dbToml = $this->projectRoot . '/configs/database.toml';
        if (!file_exists($dbToml)) {
            $io->error('configs/database.toml not found. Is TowerDNS installed?');
            return Command::FAILURE;
        }

        /** @var array<string, mixed> $dbConf */
        $dbConf = (array) Toml::decode((string) file_get_contents($dbToml), asArray: true);

        try {
            $conn = $this->buildConnection($dbConf);
        } catch (\Throwable $e) {
            $io->error('Database connection failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        // ── Find user ─────────────────────────────────────────────────────
        /** @var array{id?: string|int}|false $row */
        $row = $conn->fetchAssociative('SELECT id FROM users WHERE email = ?', [$email]);

        if ($row === false || !isset($row['id'])) {
            $io->error('No user found with e-mail: ' . $email);
            return Command::FAILURE;
        }

        $userId = (string) $row['id'];

        // ── Determine new password ────────────────────────────────────────
        if ($generate) {
            $newPassword = $this->generatePassword(24);
        } else {
            $newPassword = $io->askHidden('New password (min. 12 characters)') ?? '';
            if (strlen($newPassword) < 12) {
                $io->error('Password must be at least 12 characters long.');
                return Command::FAILURE;
            }
            $confirm = $io->askHidden('Confirm password') ?? '';
            if (!hash_equals($newPassword, $confirm)) {
                $io->error('Passwords do not match.');
                return Command::FAILURE;
            }
        }

        // ── Apply changes ─────────────────────────────────────────────────
        /** @var non-empty-string $hash */
        $hash = password_hash($newPassword, PASSWORD_ARGON2ID);

        $conn->executeStatement(
            'UPDATE users SET password_hash = ? WHERE id = ?',
            [$hash, $userId],
        );

        $revokedCount = 0;
        if (!$keepKeys) {
            $revokedCount = (int) $conn->executeStatement(
                'UPDATE api_keys SET is_active = 0 WHERE user_id = ? AND is_active = 1',
                [$userId],
            );
        }

        // ── Result ────────────────────────────────────────────────────────
        $io->success('Password for ' . $email . ' has been reset.');

        if ($generate) {
            $io->table(['Generated password'], [[$newPassword]]);
            $io->caution('Store the password securely — it will not be shown again.');
        }

        if (!$keepKeys) {
            $io->writeln(sprintf('  API keys revoked: %d', $revokedCount));
        }

        return Command::SUCCESS;
    }

    /**
     * Builds a DBAL connection from the parsed database.toml config.
     *
     * @param array<string, mixed> $dbConf
     */
    private function buildConnection(array $dbConf): Connection
    {
        $db     = (array) ($dbConf['database'] ?? []);
        $driver = (string) ($db['driver'] ?? 'pdo_sqlite');

        if ($driver === 'pdo_sqlite') {
            $params = [
                'driver' => 'pdo_sqlite',
                'path'   => (string) ($db['sqlite']['path'] ?? $this->projectRoot . '/data/database.sqlite'),
            ];
        } elseif ($driver === 'pdo_pgsql') {
            $params = [
                'driver'   => 'pdo_pgsql',
                'host'     => (string) ($db['host'] ?? 'localhost'),
                'port'     => (int) ($db['port'] ?? 5432),
                'dbname'   => (string) ($db['name'] ?? ''),
                'user'     => (string) ($db['user'] ?? ''),
                'password' => (string) ($db['password'] ?? ''),
            ];
        } else {
            $params = [
                'driver'   => 'pdo_mysql',
                'host'     => (string) ($db['host'] ?? 'localhost'),
                'port'     => (int) ($db['port'] ?? 3306),
                'dbname'   => (string) ($db['name'] ?? ''),
                'user'     => (string) ($db['user'] ?? ''),
                'password' => (string) ($db['password'] ?? ''),
                'charset'  => 'utf8mb4',
            ];
        }

        return DriverManager::getConnection($params);
    }

    /** Generates a cryptographically secure random password. */
    private function generatePassword(int $length): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*-_=+';
        $max   = strlen($chars) - 1;
        $pwd   = '';
        for ($i = 0; $i < $length; $i++) {
            $pwd .= $chars[random_int(0, $max)];
        }
        return $pwd;
    }
}

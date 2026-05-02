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
use TowerDNS\Application\Services\NullBreachedPasswordChecker;
use TowerDNS\Application\Services\PasswordGenerator;
use TowerDNS\Application\Services\PasswordPolicy;
use TowerDNS\Infrastructure\Security\HibpRangePasswordChecker;

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

        $policy    = $this->loadPasswordPolicy($conn);
        $generator = new PasswordGenerator($policy);

        // ── Determine new password ─────────────────────────────────
        if ($generate) {
            try {
                $newPassword = $generator->generate(max(24, $policy->getMinLength() + 8));
            } catch (\RuntimeException $e) {
                $io->error($e->getMessage());
                return Command::FAILURE;
            }
            $io->success('Generated password: ' . $newPassword);
            $io->warning('Write this down NOW — it will not be shown again.');
        } else {
            $newPassword = $io->askHidden(sprintf('New password (min. %d characters)', $policy->getMinLength())) ?? '';
            try {
                $policy->assertValid($newPassword);
            } catch (\InvalidArgumentException $e) {
                $io->error($e->getMessage());
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

    /**
     * Loads the password policy (incl. optional HIBP checker) from the
     * `system_settings` DB table, falling back to code defaults when the
     * row is missing.
     */
    private function loadPasswordPolicy(Connection $conn): PasswordPolicy
    {
        $settings = $this->loadSettings($conn);

        $hibpEnabled = (bool) ($settings['security.password.hibp_enabled'] ?? false);
        $checker     = $hibpEnabled
            ? new HibpRangePasswordChecker(
                new \GuzzleHttp\Client(),
                (bool) ($settings['security.password.hibp_fail_open'] ?? true),
                new \Psr\Log\NullLogger(),
                (float) ($settings['security.password.hibp_timeout'] ?? 3.0),
            )
            : new NullBreachedPasswordChecker();

        return new PasswordPolicy(
            (int) ($settings['security.password.min_length'] ?? PasswordPolicy::DEFAULT_MIN_LENGTH),
            (int) ($settings['security.password.min_score']  ?? PasswordPolicy::DEFAULT_MIN_SCORE),
            $checker,
        );
    }

    /**
     * Returns the full system_settings table as a key=>value map, with values
     * JSON-decoded. Returns an empty array when the table is missing.
     *
     * @return array<string, mixed>
     */
    private function loadSettings(Connection $conn): array
    {
        try {
            $rows = $conn->fetchAllAssociative(
                'SELECT setting_key, setting_value FROM system_settings'
            );
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $key = (string) $r['setting_key'];
            $raw = (string) $r['setting_value'];
            /** @var mixed $decoded */
            $decoded   = json_decode($raw, true);
            $out[$key] = json_last_error() === JSON_ERROR_NONE ? $decoded : $raw;
        }
        return $out;
    }
}

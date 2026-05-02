<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Console;

use Doctrine\DBAL\DriverManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TowerDNS\Infrastructure\Persistence\SchemaManager;

/**
 * Interactive CLI installer for TowerDNS.
 *
 * Run via:  php install/install-cli.php
 */
#[AsCommand(name: 'towerdns:install', description: 'Install TowerDNS interactively')]
final class InstallCommand extends Command
{
    public function __construct(private readonly string $projectRoot)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $installDir = $this->projectRoot . '/install';
        $lockFile   = $installDir . '/.lock';
        $cfgDir     = $this->projectRoot . '/configs';

        // ── Already installed? ────────────────────────────────────────────
        if (file_exists($lockFile)) {
            $io->error([
                'TowerDNS is already installed (.lock file exists).',
                'Remove ' . $lockFile . ' to reinstall.',
            ]);
            return Command::FAILURE;
        }

        // ── PHP extensions ────────────────────────────────────────────────
        foreach (['pdo', 'openssl', 'sodium', 'intl', 'mbstring'] as $ext) {
            if (!extension_loaded($ext)) {
                $io->error('Missing required PHP extension: ' . $ext);
                return Command::FAILURE;
            }
        }

        // ── configs/ directory ────────────────────────────────────────────
        if (!is_dir($cfgDir) && !mkdir($cfgDir, 0o750, true)) {
            $io->error('Cannot create directory: ' . $cfgDir);
            return Command::FAILURE;
        }
        if (!is_writable($cfgDir)) {
            $io->error('configs/ directory is not writable: ' . $cfgDir);
            return Command::FAILURE;
        }

        $io->title('TowerDNS CLI Installer');

        // ──────────────────────────────────────────────────────────────────
        // Step 1 — Database
        // ──────────────────────────────────────────────────────────────────
        $io->section('Step 1: Database configuration');

        $driver = $io->choice(
            'Database driver',
            ['pdo_mysql', 'pdo_pgsql', 'pdo_sqlite'],
            'pdo_mysql',
        );

        $db = ['driver' => $driver];

        if ($driver === 'pdo_sqlite') {
            $defaultPath = $this->projectRoot . '/data/towerdns.sqlite';
            $db['path']  = $io->ask('SQLite file path', $defaultPath) ?? $defaultPath;
            $sqliteDir   = dirname($db['path']);
            if (!is_dir($sqliteDir)) {
                mkdir($sqliteDir, 0o750, true);
            }
        } else {
            $defaultPort = $driver === 'pdo_pgsql' ? '5432' : '3306';
            $db['host']  = $io->ask('Database host', 'localhost')                    ?? 'localhost';
            $db['port']  = $io->ask('Database port', $defaultPort)                   ?? $defaultPort;
            $db['name']  = $io->ask('Database name', 'towerdns')                     ?? 'towerdns';
            $db['user']  = $io->ask('Database user', 'towerdns')                     ?? 'towerdns';
            $db['pass']  = $io->askHidden('Database password (empty = no password)') ?? '';
        }

        // ──────────────────────────────────────────────────────────────────
        // Step 2 — Admin account
        // ──────────────────────────────────────────────────────────────────
        $io->section('Step 2: Admin account');

        $adminUsername = $io->ask(
            'Admin username',
            'admin',
            static function (?string $v): string {
                $v = (string) $v;
                if (!preg_match('/^[a-zA-Z0-9\-_.]{3,50}$/', $v)) {
                    throw new \RuntimeException('Username must be 3–50 characters (a-z, 0-9, -, _, .).');
                }
                return $v;
            }
        ) ?? 'admin';

        $adminEmail = $io->ask(
            'Admin e-mail',
            null,
            static function (?string $v): string {
                if (!filter_var((string) $v, FILTER_VALIDATE_EMAIL) || strlen((string) $v) > 255) {
                    throw new \RuntimeException('Invalid e-mail address.');
                }
                return (string) $v;
            }
        ) ?? '';

        $adminPass = $this->askPassword($io);

        // ──────────────────────────────────────────────────────────────────
        // Step 3 — Application settings
        // ──────────────────────────────────────────────────────────────────
        $io->section('Step 3: Application settings');

        $appName    = $io->ask('Application name', 'TowerDNS')                  ?? 'TowerDNS';
        $appDomain  = $io->ask('Domain (optional, e.g. tower.example.com)', '') ?? '';
        $appTheme   = $io->ask('Theme name', 'default')                         ?? 'default';
        $appHttps   = $io->confirm('Force HTTPS (HSTS)', true);
        $sentryDsn  = $io->ask('Sentry DSN (leave blank to skip)', '')                                           ?? '';
        $mailerDsn  = $io->ask('Mailer DSN (e.g. smtp://user:pass@smtp.example.com:587, or blank for none)', '') ?? '';
        $mailerFrom = '';
        if ($mailerDsn !== '') {
            $mailerFrom = $io->ask('Mail from address', 'noreply@' . ($appDomain ?: 'localhost')) ?? '';
        }

        // ──────────────────────────────────────────────────────────────────
        // Step 4 — DNS Providers
        // ──────────────────────────────────────────────────────────────────
        $io->section('Step 4: DNS providers');
        $io->note('Configure at least one DNS provider.');

        $providers = [];

        if ($io->confirm('Enable deSEC provider', false)) {
            $token              = $io->ask('deSEC API token') ?? '';
            $providers['desec'] = ['token' => substr($token, 0, 512)];
        }

        if ($io->confirm('Enable PowerDNS provider', false)) {
            $pdnsUrl = $io->ask(
                'PowerDNS API base URL (e.g. http://localhost:8081)',
                null,
                static function (?string $v): string {
                    if (filter_var((string) $v, FILTER_VALIDATE_URL) === false) {
                        throw new \RuntimeException('Invalid URL.');
                    }
                    return (string) $v;
                }
            )                                                                    ?? '';
            $pdnsKey               = $io->ask('PowerDNS API key')                ?? '';
            $pdnsServer            = $io->ask('PowerDNS server ID', 'localhost') ?? 'localhost';
            $providers['powerdns'] = [
                'base_url'  => $pdnsUrl,
                'api_key'   => $pdnsKey,
                'server_id' => $pdnsServer,
            ];
        }

        if ($io->confirm('Enable Cloudflare provider', false)) {
            $cfToken                 = $io->ask('Cloudflare API token') ?? '';
            $providers['cloudflare'] = ['api_token' => substr($cfToken, 0, 512)];
        }

        if ($io->confirm('Enable INWX provider', false)) {
            $inwxUser          = $io->ask('INWX username')       ?? '';
            $inwxPass          = $io->askHidden('INWX password') ?? '';
            $providers['inwx'] = ['username' => $inwxUser, 'password' => $inwxPass];
        }

        if ($providers === []) {
            $io->error('No DNS provider configured. Aborting.');
            return Command::FAILURE;
        }

        // ──────────────────────────────────────────────────────────────────
        // Step 5 — Summary + confirmation
        // ──────────────────────────────────────────────────────────────────
        $io->section('Summary');

        $dbSummary = $driver === 'pdo_sqlite'
            ? $driver . ' → ' . $db['path']
            : $driver . ' @ ' . $db['host'] . ':' . $db['port'] . '/' . $db['name'];

        $io->definitionList(
            ['Database' => $dbSummary],
            ['Admin'     => $adminUsername . ' <' . $adminEmail . '>'],
            ['App'       => $appName . ($appDomain !== '' ? ' (' . $appDomain . ')' : '') . ' — HTTPS: ' . ($appHttps ? 'yes' : 'no')],
            ['Providers' => implode(', ', array_keys($providers))],
        );

        if (!$io->confirm('Start installation?', true)) {
            $io->warning('Aborted.');
            return Command::SUCCESS;
        }

        // ──────────────────────────────────────────────────────────────────
        // Step 6 — Installation
        // ──────────────────────────────────────────────────────────────────
        $io->writeln('Installing …');

        $now = (new \DateTime())->format('Y-m-d H:i:s');

        try {
            $conn          = $this->buildConnection($db, $this->projectRoot);
            $schemaManager = new SchemaManager($conn);

            $io->writeln('  Creating database tables …');
            $schemaManager->createTablesIfNotExist();

            $io->writeln('  Seeding system roles …');
            $schemaManager->seedSystemRoles();

            $io->writeln('  Seeding default system settings …');
            $schemaManager->seedSystemSettingsDefaults();

            $io->writeln('  Creating admin user …');
            $hash = password_hash($adminPass, PASSWORD_ARGON2ID, [
                'memory_cost' => 131072,
                'time_cost'   => 4,
                'threads'     => 4,
            ]);
            $adminId = sprintf(
                '%s-%s-%s-%s-%s',
                bin2hex(random_bytes(4)),
                bin2hex(random_bytes(2)),
                bin2hex(chr((ord(random_bytes(1)[0]) & 0x0f) | 0x40)) . bin2hex(random_bytes(1)),
                bin2hex(chr((ord(random_bytes(1)[0]) & 0x3f) | 0x80)) . bin2hex(random_bytes(1)),
                bin2hex(random_bytes(6)),
            );
            $schemaManager->seedFirstUser($adminId, $adminEmail, $hash, $adminUsername);

            $io->writeln('  Creating default account …');
            $defaultSlug = substr(strtolower(preg_replace('/[^a-z0-9]+/i', '-', $appName) ?? 'default'), 0, 64);
            $defaultSlug = trim($defaultSlug, '-') ?: 'default';
            $schemaManager->seedDefaultAccount($adminId, $appName, $defaultSlug, $now);
        } catch (\Throwable $e) {
            $io->error('Database error: ' . $e->getMessage());
            return Command::FAILURE;
        }

        // ── Write config files ────────────────────────────────────────────
        $io->writeln('  Writing config files …');
        $encKey = base64_encode(random_bytes(32));

        $this->writeLocalToml(
            $cfgDir,
            $now,
            $encKey,
            $appDomain,
            $appName,
            $appTheme,
            $appHttps,
            $sentryDsn,
            $mailerDsn,
            $mailerFrom,
        );
        $this->writeDatabaseToml($cfgDir, $now, $db);
        $this->writeProvidersToml($cfgDir, $now, $providers);

        // ── Lock file ─────────────────────────────────────────────────────
        file_put_contents($lockFile, $now);

        $io->success([
            'Installation successful!',
            'Admin username : ' . $adminUsername,
            'Admin password : ' . $adminPass,
            '',
            'Write down the password now — it will not be shown again.',
            'Remove the install/ directory for security:  rm -rf install/',
        ]);

        return Command::SUCCESS;
    }

    // ── Private helpers ───────────────────────────────────────────────────

    private function askPassword(SymfonyStyle $io): string
    {
        // Use defaults from PasswordPolicy so CLI-installer and web-installer agree.
        $policy = new \TowerDNS\Application\Services\PasswordPolicy(
            \TowerDNS\Application\Services\PasswordPolicy::DEFAULT_MIN_LENGTH,
            2,
        );

        while (true) {
            $pass1 = (string) ($io->askHidden(sprintf(
                'Admin password (min %d chars)',
                $policy->getMinLength(),
            )) ?? '');
            try {
                $policy->assertValid($pass1);
            } catch (\InvalidArgumentException $e) {
                $io->warning($e->getMessage());
                continue;
            }
            $pass2 = (string) ($io->askHidden('Repeat password') ?? '');
            if (!hash_equals($pass1, $pass2)) {
                $io->warning('Passwords do not match.');
                continue;
            }
            return $pass1;
        }
    }

    /**
     * @param array<string, string> $db
     */
    private function buildConnection(array $db, string $projectRoot): \Doctrine\DBAL\Connection
    {
        $params = match ($db['driver']) {
            'pdo_sqlite' => ['driver' => 'pdo_sqlite', 'path' => $db['path']],
            'pdo_pgsql'  => [
                'driver'   => 'pdo_pgsql',
                'host'     => $db['host'],
                'port'     => (int) $db['port'],
                'dbname'   => $db['name'],
                'user'     => $db['user'],
                'password' => $db['pass'] ?? '',
            ],
            default => [
                'driver'   => 'pdo_mysql',
                'host'     => $db['host'],
                'port'     => (int) $db['port'],
                'dbname'   => $db['name'],
                'user'     => $db['user'],
                'password' => $db['pass'] ?? '',
                'charset'  => 'utf8mb4',
            ],
        };

        return DriverManager::getConnection($params);
    }

    private function writeLocalToml(
        string $cfgDir,
        string $now,
        string $encKey,
        string $appDomain,
        string $appName,
        string $appTheme,
        bool $appHttps,
        string $sentryDsn,
        string $mailerDsn,
        string $mailerFrom,
    ): void {
        $esc        = static fn(string $s): string => addcslashes($s, '"\\');
        $forceHttps = $appHttps ? 'true' : 'false';

        $toml = <<<TOML
            # TowerDNS — Local configuration (auto-generated {$now})
            # Never commit this file!

            [security]
            encryption_key = "{$esc($encKey)}"

            [app]
            domain      = "{$esc($appDomain)}"
            force_https = {$forceHttps}
            debug       = false

            [application]
            name = "{$esc($appName)}"

            [theme]
            name = "{$esc($appTheme)}"
            TOML;

        if ($sentryDsn !== '') {
            $toml .= "\n\n[sentry]\ndsn = \"{$esc($sentryDsn)}\"";
        }

        if ($mailerDsn !== '') {
            $toml .= "\n\n[mailer]\ndsn          = \"{$esc($mailerDsn)}\"";
            if ($mailerFrom !== '') {
                $toml .= "\nfrom_address = \"{$esc($mailerFrom)}\"";
            }
        }

        $file = $cfgDir . '/config.local.toml';
        if (file_exists($file)) {
            copy($file, $file . '.bak.' . date('Y-m-d-H-i-s'));
        }
        file_put_contents($file, $toml);
        chmod($file, 0o600);
    }

    /**
     * @param array<string, string> $db
     */
    private function writeDatabaseToml(string $cfgDir, string $now, array $db): void
    {
        $esc = static fn(string $s): string => addcslashes($s, '"\\');

        if ($db['driver'] === 'pdo_sqlite') {
            $toml = <<<TOML
                # TowerDNS — Database configuration (auto-generated {$now})

                [database]
                driver = "pdo_sqlite"

                [database.sqlite]
                path = "{$esc($db['path'])}"
                TOML;
        } else {
            $port = (int) $db['port'];
            $toml = <<<TOML
                # TowerDNS — Database configuration (auto-generated {$now})

                [database]
                driver   = "{$esc($db['driver'])}"
                host     = "{$esc($db['host'])}"
                port     = {$port}
                name     = "{$esc($db['name'])}"
                user     = "{$esc($db['user'])}"
                password = "{$esc($db['pass'] ?? '')}"
                TOML;
            if ($db['driver'] === 'pdo_mysql') {
                $toml .= "\ncharset   = \"utf8mb4\"\ncollation = \"utf8mb4_unicode_ci\"";
            }
        }

        $file = $cfgDir . '/database.toml';
        if (file_exists($file)) {
            copy($file, $file . '.bak.' . date('Y-m-d-H-i-s'));
        }
        file_put_contents($file, $toml);
        chmod($file, 0o600);
    }

    /**
     * @param array<string, array<string, string>> $providers
     */
    private function writeProvidersToml(string $cfgDir, string $now, array $providers): void
    {
        $esc  = static fn(string $s): string => addcslashes($s, '"\\');
        $toml = "# TowerDNS — Provider configuration (auto-generated {$now})\n";
        $toml .= "# Never commit this file!\n\n";

        if (isset($providers['desec'])) {
            $toml .= "[providers.desec]\ntoken = \"{$esc($providers['desec']['token'])}\"\n\n";
        }
        if (isset($providers['powerdns'])) {
            $p = $providers['powerdns'];
            $toml .= "[providers.powerdns]\n"
                   . "base_url  = \"{$esc($p['base_url'])}\"\n"
                   . "api_key   = \"{$esc($p['api_key'])}\"\n"
                   . "server_id = \"{$esc($p['server_id'])}\"\n\n";
        }
        if (isset($providers['cloudflare'])) {
            $toml .= "[providers.cloudflare]\napi_token = \"{$esc($providers['cloudflare']['api_token'])}\"\n\n";
        }
        if (isset($providers['inwx'])) {
            $toml .= "[providers.inwx]\n"
                   . "username = \"{$esc($providers['inwx']['username'])}\"\n"
                   . "password = \"{$esc($providers['inwx']['password'])}\"\n\n";
        }

        $file = $cfgDir . '/providers.toml';
        if (file_exists($file)) {
            copy($file, $file . '.bak.' . date('Y-m-d-H-i-s'));
        }
        file_put_contents($file, $toml);
        chmod($file, 0o600);
    }
}

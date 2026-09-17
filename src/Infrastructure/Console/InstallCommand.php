<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Console;

use Devium\Toml\Toml;
use Doctrine\DBAL\DriverManager;
use Laminas\I18n\Translator\TranslatorInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TowerDNS\Application\Services\CredentialService;
use TowerDNS\Application\Theme\ThemeManager;
use TowerDNS\Infrastructure\Configuration\AtomicConfigurationWriter;
use TowerDNS\Infrastructure\Installation\FreshInstallBootstrapper;
use TowerDNS\Infrastructure\Installation\FreshInstallBootstrapRequest;
use TowerDNS\Infrastructure\Provider\DNSProviderFactory;

/**
 * Interactive CLI installer for TowerDNS.
 *
 * Run via: php bin/towerdns
 */
#[AsCommand(name: 'towerdns:install', description: 'Install TowerDNS interactively')]
final class InstallCommand extends Command
{
    /** @var list<string> */
    private const array REQUIRED_EXTENSIONS = ['pdo', 'openssl', 'sodium', 'intl', 'mbstring'];

    public function __construct(
        private readonly string $projectRoot,
        private readonly ?DNSProviderFactory $providerFactory = null,
        private readonly ?TranslatorInterface $translator = null,
    ) {
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
        foreach (self::missingRequiredExtensions() as $ext) {
            if (!extension_loaded($ext)) {
                $io->error('Missing required PHP extension: ' . $ext);
                return Command::FAILURE;
            }
        }

        // ── configs/ directory ────────────────────────────────────────────
        foreach ([$cfgDir, $this->projectRoot . '/cache/ratelimit', $this->projectRoot . '/data', $this->projectRoot . '/logs'] as $directory) {
            if (!is_dir($directory) && !mkdir($directory, 0o750, true)) {
                $io->error('Cannot create directory: ' . $directory);
                return Command::FAILURE;
            }
            if (!is_writable($directory)) {
                $io->error('Directory is not writable: ' . $directory);
                return Command::FAILURE;
            }
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

        $themeNames = array_keys(new ThemeManager($this->projectRoot)->getAvailable());
        $appName    = $io->ask('Application name', 'TowerDNS')                  ?? 'TowerDNS';
        $appDomain  = $io->ask('Domain (optional, e.g. tower.example.com)', '') ?? '';
        $appTheme   = (string) $io->choice('Theme', $themeNames, 'default');
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

        $factory = $this->providerFactory ?? new DNSProviderFactory();
        foreach ($factory->definitions() as $id => $definition) {
            if (!$io->confirm('Enable ' . $definition['label'] . ' provider', false)) {
                continue;
            }
            $credentials = [];
            foreach ($definition['credentials'] as $key => $field) {
                $label     = $this->translate($field['label']);
                $validator = static function (?string $value) use ($field, $label): string {
                    $value = trim($value ?? '');
                    if ($field['required'] && $value === '') {
                        throw new \RuntimeException($label . ' is required.');
                    }
                    return $value;
                };
                $credentials[$key] = $field['secret']
                    ? ($io->askHidden($label, $validator) ?? '')
                    : ($io->ask($label, $field['default'] ?? null, $validator) ?? '');
            }
            $providers[$id] = $credentials;
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
            ['Admin' => $adminUsername . ' <' . $adminEmail . '>'],
            ['App' => $appName . ($appDomain !== '' ? ' (' . $appDomain . ')' : '') . ' — HTTPS: ' . ($appHttps ? 'yes' : 'no')],
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

        $now = new \DateTime()->format('Y-m-d H:i:s');

        try {
            $conn         = $this->buildConnection($db);
            $bootstrapper = new FreshInstallBootstrapper($conn);

            $io->writeln('  Creating database tables …');
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
            $io->writeln('  Creating default account …');
            $defaultSlug = substr(strtolower(preg_replace('/[^a-z0-9]+/i', '-', $appName) ?? 'default'), 0, 64);
            $defaultSlug = trim($defaultSlug, '-') ?: 'default';
            $bootstrapper->bootstrap(new FreshInstallBootstrapRequest(
                $adminId,
                $adminEmail,
                $hash,
                $adminUsername,
                $appName,
                $defaultSlug,
                $now,
            ));
        } catch (\Throwable) {
            $io->error('Database setup failed. Check the database configuration and installation state.');
            return Command::FAILURE;
        }

        // ── Write config files ────────────────────────────────────────────
        $io->writeln('  Writing config files …');
        try {
            $encKey = $this->resolveEncryptionKey($cfgDir . '/config.local.toml');

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
        } catch (\Throwable) {
            $io->error('Configuration could not be written. The database was not marked as installed.');
            return Command::FAILURE;
        }

        // ── Lock file ─────────────────────────────────────────────────────
        new AtomicConfigurationWriter()->write($lockFile, $now);

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

    private function translate(string $message): string
    {
        return $this->translator?->translate($message) ?? $message;
    }

    /** @return list<string> */
    public static function missingRequiredExtensions(): array
    {
        return array_values(array_filter(
            self::REQUIRED_EXTENSIONS,
            static fn(string $extension): bool => !extension_loaded($extension),
        ));
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
    private function buildConnection(array $db): \Doctrine\DBAL\Connection
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

            [session]
            cookie_secure = {$forceHttps}

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
        $this->writeSecureFile($file, $toml);
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
        $this->writeSecureFile($file, $toml);
        chmod($file, 0o600);
    }

    /**
     * @param array<string, array<string, string>> $providers
     */
    private function writeProvidersToml(string $cfgDir, string $now, array $providers): void
    {
        $toml = "# TowerDNS — Provider configuration (auto-generated {$now})\n";
        $toml .= "# Never commit this file!\n\n";
        $toml .= Toml::encode(['providers' => $providers]);

        $file = $cfgDir . '/providers.toml';
        if (file_exists($file)) {
            copy($file, $file . '.bak.' . date('Y-m-d-H-i-s'));
        }
        $this->writeSecureFile($file, $toml);
        chmod($file, 0o600);
    }

    private function writeSecureFile(string $file, string $contents): void
    {
        new AtomicConfigurationWriter()->write($file, $contents);
    }

    private function resolveEncryptionKey(string $configFile): string
    {
        if (!is_file($configFile)) {
            return CredentialService::generateKey();
        }
        $config = (array) Toml::decode((string) file_get_contents($configFile), asArray: true);
        $key    = (string) ($config['security']['encryption_key'] ?? '');
        new CredentialService($key);
        return $key;
    }
}

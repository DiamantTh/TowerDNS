<?php

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TowerDNS\Infrastructure\Installation\DocumentRootPolicy;
use TowerDNS\Infrastructure\Ui\AssetManifest;

/** Read-only preflight for a fresh installation; database connection is optional until configured. */
#[AsCommand(name: 'towerdns:install:check', description: 'Check prerequisites for a fresh TowerDNS installation')]
final class InstallCheckCommand extends Command
{
    public function __construct(private readonly string $projectRoot)
    {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: table or json', 'table', ['table', 'json']);
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $checks = [];
        $add    = static function (string $name, string $status, string $detail) use (&$checks): void {
            $checks[] = ['check' => $name, 'status' => $status, 'detail' => $detail];
        };
        $add('php', PHP_VERSION_ID >= 80403 ? 'PASS' : 'FAIL', PHP_VERSION_ID >= 80403 ? PHP_VERSION : 'PHP 8.4.3 or newer is required');
        foreach (['pdo', 'openssl', 'sodium', 'intl', 'mbstring', 'json'] as $extension) {
            $add('extension.' . $extension, extension_loaded($extension) ? 'PASS' : 'FAIL', extension_loaded($extension) ? 'loaded' : 'install/enable the PHP extension');
        }
        foreach (['pdo_sqlite', 'pdo_mysql', 'pdo_pgsql'] as $extension) {
            $add('database_driver.' . $extension, extension_loaded($extension) ? 'PASS' : 'WARN', extension_loaded($extension) ? 'available' : 'optional unless this database is selected');
        }
        $add('composer_autoload', is_file($this->projectRoot . '/vendor/autoload.php') ? 'PASS' : 'FAIL', 'run composer install --no-dev');
        $add('composer_lock', is_file($this->projectRoot . '/composer.lock') ? 'PASS' : 'FAIL', 'composer.lock is required for reproducible deployment');
        foreach (['data', 'logs', 'cache/ratelimit', 'configs'] as $relative) {
            $path = $this->projectRoot . '/' . $relative;
            $add('path.' . $relative, is_dir($path) && is_writable($path) ? 'PASS' : 'WARN', 'create this private runtime directory and grant write access to the TowerDNS PHP user');
        }
        try {
            new AssetManifest($this->projectRoot)->verifyComplete();
            $add('frontend_assets', 'PASS', 'activated production manifest and assets are complete');
        } catch (\Throwable) {
            $add('frontend_assets', 'FAIL', 'build and activate production assets before deployment');
        }
        $docroot = $_SERVER['DOCUMENT_ROOT'] ?? null;
        $matches = DocumentRootPolicy::matches($this->projectRoot, is_string($docroot) ? $docroot : null);
        $add('document_root', $matches ? 'PASS' : 'WARN', $matches ? 'httpdocs is the active document root' : 'configure the web server document root to the project httpdocs directory');
        $failures = count(array_filter($checks, static fn(array $check): bool => $check['status'] === 'FAIL'));
        if ((string) $input->getOption('format') === 'json') {
            $output->writeln(json_encode(['ready' => $failures === 0, 'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            foreach ($checks as $check) {
                $output->writeln(sprintf('%s  %s: %s', $check['status'], $check['check'], $check['detail']));
            }
        }

        return $failures === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}

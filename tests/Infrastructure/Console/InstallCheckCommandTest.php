<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Console;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use TowerDNS\Infrastructure\Console\InstallCheckCommand;

/** @psalm-api Discovered by PHPUnit's test suite. */
final class InstallCheckCommandTest extends TestCase
{
    public function testJsonCheckIsReadOnlyAndReportsMissingPreparedInstallationParts(): void
    {
        $root = sys_get_temp_dir() . '/towerdns-install-check-' . bin2hex(random_bytes(6));
        mkdir($root, 0o700, true);
        file_put_contents($root . '/sentinel', 'unchanged');
        $tester = new CommandTester(new InstallCheckCommand($root));

        try {
            self::assertSame(1, $tester->execute(['--format' => 'json']));
            $result = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
            self::assertFalse($result['ready']);
            self::assertContains('frontend_assets', array_column($result['checks'], 'check'));
            self::assertSame('unchanged', file_get_contents($root . '/sentinel'));
            $entries = scandir($root);
            self::assertIsArray($entries);
            self::assertSame(['sentinel'], array_values(array_diff($entries, ['.', '..'])));
        } finally {
            unlink($root . '/sentinel');
            rmdir($root);
        }
    }

    public function testPreflightValidatesConfiguredSqliteWithoutChangingTheDatabase(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is not available in this runtime.');
        }

        $root = sys_get_temp_dir() . '/towerdns-install-check-db-' . bin2hex(random_bytes(6));
        mkdir($root . '/configs', 0o700, true);
        $database = $root . '/empty.sqlite';
        $pdo      = new \PDO('sqlite:' . $database);
        $pdo->exec('CREATE TABLE sentinel (value TEXT)');
        $pdo->exec("INSERT INTO sentinel VALUES ('untouched')");
        unset($pdo);
        file_put_contents($root . '/configs/config.local.toml', "[security]\nencryption_key = \"" . base64_encode(str_repeat('k', 32)) . "\"\n\n[app]\ndomain = \"dns.example.test\"\nforce_https = true\n");
        file_put_contents($root . '/configs/database.toml', "[database]\ndriver = \"pdo_sqlite\"\n\n[database.sqlite]\npath = \"{$database}\"\n");

        try {
            $tester = new CommandTester(new InstallCheckCommand($root));
            $tester->execute(['--format' => 'json']);
            $result = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
            $checks = array_column($result['checks'], null, 'check');

            self::assertSame('PASS', $checks['database_connection']['status']);
            self::assertSame('WARN', $checks['database_state']['status']);
            self::assertSame('PASS', $checks['application_key']['status']);
            self::assertSame('PASS', $checks['webauthn_origin']['status']);
            $statement = new \PDO('sqlite:' . $database)->query('SELECT value FROM sentinel');
            self::assertInstanceOf(\PDOStatement::class, $statement);
            self::assertSame('untouched', $statement->fetchColumn());
        } finally {
            unlink($root . '/configs/config.local.toml');
            unlink($root . '/configs/database.toml');
            unlink($database);
            rmdir($root . '/configs');
            rmdir($root);
        }
    }

    public function testMalformedPresentConfigurationIsBlockingInsteadOfTreatedAsUnconfigured(): void
    {
        $root = sys_get_temp_dir() . '/towerdns-install-check-invalid-' . bin2hex(random_bytes(6));
        mkdir($root . '/configs', 0o700, true);
        file_put_contents($root . '/configs/database.toml', '[database' . "\n");

        try {
            $tester = new CommandTester(new InstallCheckCommand($root));
            self::assertSame(1, $tester->execute(['--format' => 'json']));
            $result = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
            $checks = array_column($result['checks'], null, 'check');
            self::assertSame('FAIL', $checks['database_configuration']['status']);
            self::assertSame('SKIP', $checks['database_connection']['status']);
        } finally {
            unlink($root . '/configs/database.toml');
            rmdir($root . '/configs');
            rmdir($root);
        }
    }
}

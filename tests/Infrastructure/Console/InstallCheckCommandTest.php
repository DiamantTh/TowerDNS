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
}

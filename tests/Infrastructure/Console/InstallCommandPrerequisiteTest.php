<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Console;

use PHPUnit\Framework\TestCase;
use TowerDNS\Infrastructure\Console\InstallCommand;

final class InstallCommandPrerequisiteTest extends TestCase
{
    public function testReportsEveryMissingRequiredExtensionIncludingSodium(): void
    {
        $missing = InstallCommand::missingRequiredExtensions();

        foreach (['pdo', 'openssl', 'sodium', 'intl', 'mbstring'] as $extension) {
            self::assertSame(!extension_loaded($extension), in_array($extension, $missing, true));
        }
    }
}

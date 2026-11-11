<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Application\Validation;

use PHPUnit\Framework\TestCase;
use TowerDNS\Application\Validation\HostnameValidator;

/** @psalm-api PHPUnit discovers this test at runtime. */
final class HostnameValidatorTest extends TestCase
{
    public function testAcceptsHostsUsedByLocalAndNetworkInstallations(): void
    {
        self::assertTrue(HostnameValidator::isValid('localhost'));
        self::assertTrue(HostnameValidator::isValid('tower.example.test'));
        self::assertTrue(HostnameValidator::isValid('192.0.2.42'));
        self::assertTrue(HostnameValidator::isValid('2001:db8::42'));
    }

    public function testRejectsValuesThatCannotSafelyBeUsedAsHosts(): void
    {
        self::assertFalse(HostnameValidator::isValid(''));
        self::assertFalse(HostnameValidator::isValid('tower.example.test/path'));
        self::assertFalse(HostnameValidator::isValid('https://tower.example.test'));
        self::assertFalse(HostnameValidator::isValid('-tower.example.test'));
        self::assertFalse(HostnameValidator::isValid(str_repeat('a', 254)));
    }
}

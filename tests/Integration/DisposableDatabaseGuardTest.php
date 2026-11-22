<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Integration;

use PHPUnit\Framework\TestCase;

/** @psalm-api Runtime discovery by PHPUnit is not statically visible. */
final class DisposableDatabaseGuardTest extends TestCase
{
    public function testRequiresExplicitConfirmationBeforeAllowingDestructiveIntegrationDatabase(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('drops its tables');

        DisposableDatabaseGuard::assertSafeTarget('mariadb', 'towerdns_test', null);
    }

    public function testRejectsUnlabeledDatabaseEvenWithExplicitConfirmation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must end in _test');

        DisposableDatabaseGuard::assertSafeTarget('postgresql', 'production', DisposableDatabaseGuard::CONFIRMATION);
    }

    public function testAllowsClearlyNamedDisposableDatabaseWithExplicitConfirmation(): void
    {
        $this->expectNotToPerformAssertions();
        DisposableDatabaseGuard::assertSafeTarget('mariadb', 'towerdns_test', DisposableDatabaseGuard::CONFIRMATION);
    }
}

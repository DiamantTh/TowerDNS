<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Application\Services;

use PHPUnit\Framework\TestCase;
use TowerDNS\Application\Repository\AccountRepositoryInterface;
use TowerDNS\Application\Services\ActiveAccountService;
use TowerDNS\Domain\Account\Account;
use TowerDNS\Domain\Auth\User;

final class ActiveAccountServiceTest extends TestCase
{
    public function testOnlyAnActiveMembershipAccountCanBeSelected(): void
    {
        $repository = $this->createMock(AccountRepositoryInterface::class);
        $repository->method('findByUserId')->willReturn([
            new Account(1, 'Personal', 'personal', 'user-1', true, '2026-09-16 00:00:00'),
            new Account(2, 'Disabled', 'disabled', 'user-1', false, '2026-09-16 00:00:00'),
        ]);
        $service = new ActiveAccountService($repository);
        $user    = new User('user-1', 'user@example.test');

        self::assertSame(1, $service->select($user, 1)->id);
        self::assertNull($service->resolve($user, 2));
        self::assertNull($service->resolve($user, 99));
    }
}

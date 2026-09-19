<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Application\Services;

use PHPUnit\Framework\TestCase;
use TowerDNS\Application\Exception\ResourceLimitExceededException;
use TowerDNS\Application\Repository\AccountRepositoryInterface;
use TowerDNS\Application\Repository\AccountResourceLimitsRepositoryInterface;
use TowerDNS\Application\Repository\ManagedZoneRepositoryInterface;
use TowerDNS\Application\Repository\ProviderAccountRepositoryInterface;
use TowerDNS\Application\Services\ResourceLimitService;
use TowerDNS\Domain\Account\AccountResourceLimits;

final class ResourceLimitServiceTest extends TestCase
{
    public function testNullLimitsAreUnlimited(): void
    {
        $service = $this->service(new AccountResourceLimits(1, null, null, null), 100, 100, 100);
        $service->assertCanCreateZone(1);
        $service->assertCanAddMember(1);
        $service->assertCanCreateProviderAccount(1);
        self::addToAssertionCount(3);
    }

    public function testZeroAndReachedLimitsBlockOnlyTheMatchingResource(): void
    {
        $service = $this->service(new AccountResourceLimits(1, 0, 1, 2), 0, 1, 2);
        try {
            $service->assertCanCreateZone(1);
            self::fail('Expected zone limit error.');
        } catch (ResourceLimitExceededException $e) {
            self::assertSame(ResourceLimitExceededException::ZONES, $e->codeId);
        }
        try {
            $service->assertCanAddMember(1);
            self::fail('Expected member limit error.');
        } catch (ResourceLimitExceededException $e) {
            self::assertSame(ResourceLimitExceededException::MEMBERS, $e->codeId);
        }
        try {
            $service->assertCanCreateProviderAccount(1);
            self::fail('Expected provider account limit error.');
        } catch (ResourceLimitExceededException $e) {
            self::assertSame(ResourceLimitExceededException::PROVIDER_ACCOUNTS, $e->codeId);
        }
    }

    public function testNegativeLimitsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AccountResourceLimits(1, -1, null, null);
    }

    private function service(AccountResourceLimits $limits, int $zones, int $members, int $providers): ResourceLimitService
    {
        $limitsRepository = $this->createMock(AccountResourceLimitsRepositoryInterface::class);
        $limitsRepository->method('findByAccountId')->willReturn($limits);
        $managedZones = $this->createMock(ManagedZoneRepositoryInterface::class);
        $managedZones->method('countByAccountId')->willReturn($zones);
        $accounts = $this->createMock(AccountRepositoryInterface::class);
        $accounts->method('countMemberships')->willReturn($members);
        $providerAccounts = $this->createMock(ProviderAccountRepositoryInterface::class);
        $providerAccounts->method('countByAccountId')->willReturn($providers);
        return new ResourceLimitService($limitsRepository, $managedZones, $accounts, $providerAccounts);
    }
}

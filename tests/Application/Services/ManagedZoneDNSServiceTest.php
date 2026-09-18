<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Application\Services;

use PHPUnit\Framework\TestCase;
use TowerDNS\Application\Contracts\AccountProviderFactoryInterface;
use TowerDNS\Application\Contracts\Capability;
use TowerDNS\Application\Contracts\DNSProviderInterface;
use TowerDNS\Application\Contracts\ProviderCapabilitySet;
use TowerDNS\Application\Repository\AccountRepositoryInterface;
use TowerDNS\Application\Repository\ManagedZoneRepositoryInterface;
use TowerDNS\Application\Repository\ProviderAccountRepositoryInterface;
use TowerDNS\Application\Repository\ZoneMembershipRepositoryInterface;
use TowerDNS\Application\Services\AuthorizationService;
use TowerDNS\Application\Services\ManagedZoneDNSService;
use TowerDNS\Application\Services\PermissionService;
use TowerDNS\Application\Services\RbacPermissionChecker;
use TowerDNS\Domain\Account\ManagedZone;
use TowerDNS\Domain\Account\ProviderAccount;
use TowerDNS\Domain\Account\TeamRole;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Domain\DNS\DNSSECProfile;
use TowerDNS\Domain\DNS\DNSSECState;
use TowerDNS\Domain\DNS\Record;
use TowerDNS\Domain\DNS\RecordType;
use TowerDNS\Domain\DNS\Zone;

final class ManagedZoneDNSServiceTest extends TestCase
{
    public function testRecordOperationResolvesOnlyTheManagedZoneProviderReference(): void
    {
        $managed = new ManagedZone(7, 42, 9, 'same-external-id', 'example.test', '2026-09-17 12:00:00');
        $zones   = $this->createMock(ManagedZoneRepositoryInterface::class);
        $zones->expects(self::exactly(2))->method('findByIdForAccount')->with(7, 42)->willReturn($managed);
        $providerAccounts = $this->createMock(ProviderAccountRepositoryInterface::class);
        $providerAccounts->expects(self::once())->method('findById')->with(9)->willReturn(new ProviderAccount(9, 42, 'fake', 'Fake', 'ciphertext', 3, true, '2026-09-17 12:00:00'));
        $provider = new class implements DNSProviderInterface {
            public ?Record $created = null;
            public function id(): string
            {
                return 'fake';
            }
            public function displayName(): string
            {
                return 'Fake';
            }
            public function capabilities(): ProviderCapabilitySet
            {
                return new ProviderCapabilitySet([Capability::RECORD_CREATE => true]);
            }
            public function listZones(): array
            {
                return [];
            }
            public function createZone(string $zoneName): Zone
            {
                return new Zone('', $zoneName, 'fake', true);
            }
            public function deleteZone(string $zoneId): void {}
            public function listRecords(string $zoneId): array
            {
                return [];
            }
            public function createRecord(Record $record): Record
            {
                return $this->created = $record;
            }
            public function updateRecord(Record $record): Record
            {
                return $record;
            }
            public function deleteRecord(string $zoneId, string $recordId): void {}
            public function getDnssecProfile(string $zoneId): DNSSECProfile
            {
                return new DNSSECProfile($zoneId, DNSSECState::UNKNOWN);
            }
            public function executeDnssecAction(string $zoneId, string $action, array $payload = []): DNSSECProfile
            {
                return new DNSSECProfile($zoneId, DNSSECState::UNKNOWN);
            }
        };
        $factory = $this->createMock(AccountProviderFactoryInterface::class);
        $factory->expects(self::once())->method('buildProvider')->willReturn($provider);
        $accounts = $this->createMock(AccountRepositoryInterface::class);
        $accounts->method('getEffectiveRole')->with(42, 'user')->willReturn(TeamRole::DNS_MANAGER);
        $memberships = $this->createMock(ZoneMembershipRepositoryInterface::class);
        $rbac        = new RbacPermissionChecker();
        $permissions = new PermissionService($accounts, $memberships, new AuthorizationService($rbac), $rbac, $zones);
        $service     = new ManagedZoneDNSService($permissions, $zones, $providerAccounts, $factory);

        $service->createRecord(new User('user', 'user@example.test'), 42, 7, new Record('', 'untrusted-zone', 'www', RecordType::A, 300, '192.0.2.1'));

        self::assertSame('same-external-id', $provider->created?->zoneId);
    }
}

<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Provider;

use PHPUnit\Framework\TestCase;
use TowerDNS\Application\Contracts\AccountProviderFactoryInterface;
use TowerDNS\Application\Contracts\Capability;
use TowerDNS\Application\Contracts\DNSProviderInterface;
use TowerDNS\Application\Contracts\ProviderCapabilitySet;
use TowerDNS\Application\Exception\AuthorizationException;
use TowerDNS\Application\Exception\CapabilityException;
use TowerDNS\Application\Provider\ProviderRegistry;
use TowerDNS\Application\Repository\AccountRepositoryInterface;
use TowerDNS\Application\Repository\ProviderAccountRepositoryInterface;
use TowerDNS\Application\Repository\ZoneMembershipRepositoryInterface;
use TowerDNS\Application\Services\AuthorizationService;
use TowerDNS\Application\Services\DNSManagementService;
use TowerDNS\Application\Services\PermissionService;
use TowerDNS\Application\Services\RbacPermissionChecker;
use TowerDNS\Domain\Auth\Permission;
use TowerDNS\Domain\Auth\Role;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Domain\DNS\DNSSECProfile;
use TowerDNS\Domain\DNS\DNSSECState;
use TowerDNS\Domain\DNS\Record;
use TowerDNS\Domain\DNS\Zone;

final class DnsManagementServiceTest extends TestCase
{
    public function testListZonesRequiresPermission(): void
    {
        $user    = new User('u1', 'u@example.com', []);
        $service = $this->makeService([$this->makeProvider()]);

        $this->expectException(AuthorizationException::class);
        $service->listZones($user, 'fake');
    }

    public function testCreateZoneEnforcesCapability(): void
    {
        $role = new Role('r', 'reader', [Permission::ZONE_CREATE]);
        $user = new User('u1', 'u@example.com', [$role]);

        $provider = $this->makeProvider(capabilities: [Capability::ZONE_CREATE => false]);
        $service  = $this->makeService([$provider]);

        $this->expectException(CapabilityException::class);
        $service->createZone($user, 'fake', 'example.com');
    }

    public function testCreateZoneNormalisesIdn(): void
    {
        $role = new Role('r', 'admin', [Permission::ZONE_CREATE]);
        $user = new User('u1', 'u@example.com', [$role]);

        $provider = $this->makeProvider();
        $service  = $this->makeService([$provider]);

        $zone = $service->createZone($user, 'fake', 'müller.eu');
        self::assertSame('xn--mller-kva.eu', $zone->name);
    }

    public function testDeleteRrsetRequiresVerifiedAbsence(): void
    {
        $role     = new Role('r', 'editor', [Permission::RECORD_DELETE]);
        $user     = new User('u1', 'u@example.com', [$role]);
        $provider = new class implements DNSProviderInterface, \TowerDNS\Application\Contracts\RrsetProviderInterface {
            public bool $deleted = false;
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
                return new ProviderCapabilitySet([Capability::RECORD_DELETE => true, Capability::RECORD_LIST => true]);
            }
            public function listZones(): array
            {
                return [];
            }
            public function createZone(string $zoneName): Zone
            {
                return new Zone($zoneName, $zoneName, 'fake', true);
            }
            public function deleteZone(string $zoneId): void {}
            public function listRecords(string $zoneId): array
            {
                return [];
            }
            public function createRecord(Record $record): Record
            {
                return $record;
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
            public function listRrsets(string $zoneId): array
            {
                return [];
            }
            public function replaceRrset(\TowerDNS\Domain\DNS\Rrset $rrset): \TowerDNS\Domain\DNS\Rrset
            {
                return $rrset;
            }
            public function deleteRrset(string $zoneId, string $ownerName, string $type): void
            {
                $this->deleted = $zoneId === 'example.org' && $ownerName === '_443._tcp' && $type === 'TLSA';
            }
        };

        $service = $this->makeService([$provider]);
        $service->deleteRrset($user, 'fake', 'example.org', '_443._tcp', 'tlsa');
        self::assertTrue($provider->deleted);
    }

    /** @param list<DNSProviderInterface> $providers */
    private function makeService(array $providers): DNSManagementService
    {
        $rbac          = new RbacPermissionChecker();
        $authorization = new AuthorizationService($rbac);

        return new DNSManagementService(
            $authorization,
            new ProviderRegistry($providers),
            $this->createMock(ProviderAccountRepositoryInterface::class),
            $this->createMock(AccountProviderFactoryInterface::class),
            new PermissionService(
                $this->createMock(AccountRepositoryInterface::class),
                $this->createMock(ZoneMembershipRepositoryInterface::class),
                $authorization,
                $rbac,
            ),
        );
    }

    /**
     * @param array<string, bool> $capabilities
     */
    private function makeProvider(array $capabilities = []): DNSProviderInterface
    {
        $defaultCaps = [
            Capability::ZONE_LIST   => true,
            Capability::ZONE_CREATE => true,
            Capability::ZONE_DELETE => true,
            Capability::RECORD_LIST => true,
        ];
        $caps = $capabilities + $defaultCaps;

        return new readonly class ($caps) implements DNSProviderInterface {
            /** @param array<string, bool> $caps */
            public function __construct(private array $caps) {}
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
                return new ProviderCapabilitySet($this->caps);
            }
            public function listZones(): array
            {
                return [];
            }
            public function createZone(string $zoneName): Zone
            {
                return new Zone($zoneName, $zoneName, 'fake', true);
            }
            public function deleteZone(string $zoneId): void {}
            public function listRecords(string $zoneId): array
            {
                return [];
            }
            public function createRecord(Record $record): Record
            {
                return $record;
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
    }
}

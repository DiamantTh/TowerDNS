<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Provider;

use PHPUnit\Framework\TestCase;
use TowerDNS\Application\Contracts\Capability;
use TowerDNS\Application\Contracts\DnsProviderInterface;
use TowerDNS\Application\Contracts\ProviderCapabilitySet;
use TowerDNS\Application\Exception\AuthorizationException;
use TowerDNS\Application\Exception\CapabilityException;
use TowerDNS\Application\Provider\ProviderRegistry;
use TowerDNS\Application\Services\AuthorizationService;
use TowerDNS\Application\Services\DnsManagementService;
use TowerDNS\Domain\Auth\Permission;
use TowerDNS\Domain\Auth\Role;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Domain\DNS\DnssecProfile;
use TowerDNS\Domain\DNS\DnssecState;
use TowerDNS\Domain\DNS\Record;
use TowerDNS\Domain\DNS\Zone;

final class DnsManagementServiceTest extends TestCase
{
    public function testListZonesRequiresPermission(): void
    {
        $user    = new User('u1', 'u@example.com', []);
        $service = new DnsManagementService(
            new AuthorizationService(),
            new ProviderRegistry([$this->makeProvider()]),
        );

        $this->expectException(AuthorizationException::class);
        $service->listZones($user, 'fake');
    }

    public function testCreateZoneEnforcesCapability(): void
    {
        $role = new Role('r', 'reader', [Permission::ZONE_CREATE]);
        $user = new User('u1', 'u@example.com', [$role]);

        $provider = $this->makeProvider(capabilities: [Capability::ZONE_CREATE => false]);
        $service  = new DnsManagementService(
            new AuthorizationService(),
            new ProviderRegistry([$provider]),
        );

        $this->expectException(CapabilityException::class);
        $service->createZone($user, 'fake', 'example.com');
    }

    public function testCreateZoneNormalisesIdn(): void
    {
        $role = new Role('r', 'admin', [Permission::ZONE_CREATE]);
        $user = new User('u1', 'u@example.com', [$role]);

        $provider = $this->makeProvider();
        $service  = new DnsManagementService(
            new AuthorizationService(),
            new ProviderRegistry([$provider]),
        );

        $zone = $service->createZone($user, 'fake', 'müller.eu');
        self::assertSame('xn--mller-kva.eu', $zone->name);
    }

    /**
     * @param array<string, bool> $capabilities
     */
    private function makeProvider(array $capabilities = []): DnsProviderInterface
    {
        $defaultCaps = [
            Capability::ZONE_LIST   => true,
            Capability::ZONE_CREATE => true,
            Capability::ZONE_DELETE => true,
            Capability::RECORD_LIST => true,
        ];
        $caps = $capabilities + $defaultCaps;

        return new readonly class ($caps) implements DnsProviderInterface {
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
            public function getDnssecProfile(string $zoneId): DnssecProfile
            {
                return new DnssecProfile($zoneId, DnssecState::UNKNOWN);
            }
            public function executeDnssecAction(string $zoneId, string $action, array $payload = []): DnssecProfile
            {
                return new DnssecProfile($zoneId, DnssecState::UNKNOWN);
            }
        };
    }
}

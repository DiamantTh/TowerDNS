<?php

declare(strict_types=1);

namespace TowerDNS\Application\Services;

use TowerDNS\Application\Contracts\DnsProviderInterface;
use TowerDNS\Application\Exception\CapabilityException;
use TowerDNS\Domain\Auth\Permission;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Domain\DNS\DnssecProfile;
use TowerDNS\Domain\DNS\Record;
use TowerDNS\Domain\DNS\Zone;

final class DnsManagementService
{
    public function __construct(
        private readonly AuthorizationService $authorizationService,
        private readonly DnsProviderInterface $provider
    ) {
    }

    /**
     * @return list<Zone>
     */
    public function listZones(User $user): array
    {
        $this->authorizationService->assert($user, Permission::ZONE_READ);

        return $this->provider->listZones();
    }

    public function createZone(User $user, string $zoneName): Zone
    {
        $this->authorizationService->assert($user, Permission::ZONE_CREATE);
        $this->assertCapability('zone.create');

        return $this->provider->createZone($zoneName);
    }

    public function deleteZone(User $user, string $zoneId): void
    {
        $this->authorizationService->assert($user, Permission::ZONE_DELETE);
        $this->assertCapability('zone.delete');

        $this->provider->deleteZone($zoneId);
    }

    /**
     * @return list<Record>
     */
    public function listRecords(User $user, string $zoneId): array
    {
        $this->authorizationService->assert($user, Permission::RECORD_READ);

        return $this->provider->listRecords($zoneId);
    }

    public function createRecord(User $user, Record $record): Record
    {
        $this->authorizationService->assert($user, Permission::RECORD_CREATE);
        $this->assertCapability('record.create');

        return $this->provider->createRecord($record);
    }

    public function updateRecord(User $user, Record $record): Record
    {
        $this->authorizationService->assert($user, Permission::RECORD_UPDATE);
        $this->assertCapability('record.update');

        return $this->provider->updateRecord($record);
    }

    public function deleteRecord(User $user, string $zoneId, string $recordId): void
    {
        $this->authorizationService->assert($user, Permission::RECORD_DELETE);
        $this->assertCapability('record.delete');

        $this->provider->deleteRecord($zoneId, $recordId);
    }

    public function getDnssecProfile(User $user, string $zoneId): DnssecProfile
    {
        $this->authorizationService->assert($user, Permission::DNSSEC_STATUS_READ);
        $this->assertCapability('dnssec.status.read');

        return $this->provider->getDnssecProfile($zoneId);
    }

    /**
     * @param array<string, scalar|array<array-key, scalar>|null> $payload
     */
    public function executeDnssecAction(User $user, string $zoneId, string $action, array $payload = []): DnssecProfile
    {
        $this->authorizationService->assert($user, Permission::DNSSEC_ACTION_EXECUTE);
        $this->assertCapability('dnssec.action.execute');

        return $this->provider->executeDnssecAction($zoneId, $action, $payload);
    }

    private function assertCapability(string $capability): void
    {
        if (!$this->provider->capabilities()->supports($capability)) {
            throw new CapabilityException(sprintf('Provider %s unterstuetzt %s nicht.', $this->provider->id(), $capability));
        }
    }
}

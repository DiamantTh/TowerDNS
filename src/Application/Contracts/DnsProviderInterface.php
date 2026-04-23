<?php

declare(strict_types=1);

namespace TowerDNS\Application\Contracts;

use TowerDNS\Domain\DNS\DnssecProfile;
use TowerDNS\Domain\DNS\Record;
use TowerDNS\Domain\DNS\Zone;

interface DnsProviderInterface
{
    public function id(): string;

    public function displayName(): string;

    public function capabilities(): ProviderCapabilitySet;

    /**
     * @return list<Zone>
     */
    public function listZones(): array;

    public function createZone(string $zoneName): Zone;

    public function deleteZone(string $zoneId): void;

    /**
     * @return list<Record>
     */
    public function listRecords(string $zoneId): array;

    public function createRecord(Record $record): Record;

    public function updateRecord(Record $record): Record;

    public function deleteRecord(string $zoneId, string $recordId): void;

    public function getDnssecProfile(string $zoneId): DnssecProfile;

    /**
     * @param array<string, scalar|array<array-key, scalar>|null> $payload
     */
    public function executeDnssecAction(string $zoneId, string $action, array $payload = []): DnssecProfile;
}

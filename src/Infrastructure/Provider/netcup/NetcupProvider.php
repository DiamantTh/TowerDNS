<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Provider\netcup;

use TowerDNS\Application\Contracts\Capability;
use TowerDNS\Application\Exception\CapabilityException;
use TowerDNS\Domain\DNS\DnssecProfile;
use TowerDNS\Domain\DNS\DnssecState;
use TowerDNS\Domain\DNS\Record;
use TowerDNS\Domain\DNS\RecordType;
use TowerDNS\Domain\DNS\Rrset;
use TowerDNS\Domain\DNS\Zone;
use TowerDNS\Infrastructure\Provider\AbstractDnsProvider;

/**
 * Netcup legacy CCP DNS adapter.
 *
 * Netcup only exposes DNS zones belonging to known domains, rather than a
 * list-zones endpoint for ordinary customer accounts. The explicit zone allow
 * list is therefore a safety boundary as well as an API limitation.
 */
final class NetcupProvider extends AbstractDnsProvider
{
    public const string ID = 'netcup';

    /** @var list<string> */
    private array $zones;

    /** @param list<string> $zones */
    public function __construct(private readonly NetcupApiClient $client, array $zones)
    {
        $this->zones = array_values(array_unique(array_filter(array_map(
            static fn(string $zone): string => rtrim(trim($zone), '.'),
            $zones,
        ))));
        parent::__construct();
    }

    public function id(): string
    {
        return self::ID;
    }

    public function displayName(): string
    {
        return 'Netcup (CCP DNS)';
    }

    protected function capabilityMap(): array
    {
        return [
            Capability::ZONE_LIST                   => true,
            Capability::ZONE_READ                   => true,
            Capability::ZONE_CREATE                 => false,
            Capability::ZONE_UPDATE                 => true,
            Capability::ZONE_DELETE                 => false,
            Capability::RECORD_LIST                 => true,
            Capability::RECORD_CREATE               => true,
            Capability::RECORD_UPDATE               => true,
            Capability::RECORD_DELETE               => true,
            Capability::RECORD_COMMENT              => false,
            Capability::DNSSEC_STATUS_READ          => false,
            Capability::DNSSEC_AUTO_MANAGED         => false,
            Capability::DNSSEC_DS_READ              => false,
            Capability::DNSSEC_ACTION_EXECUTE       => false,
            Capability::DNSSEC_KEY_LIST             => false,
            Capability::DNSSEC_KEY_ROLLOVER         => false,
            Capability::PROVIDER_CREDENTIALS_MANAGE => true,
        ];
    }

    public function listZones(): array
    {
        return array_map(fn(string $zone): Zone => new Zone($zone, $zone, self::ID, true), $this->zones);
    }

    public function createZone(string $zoneName): Zone
    {
        throw new CapabilityException('Netcup CCP DNS kann keine Zonen erzeugen; die Domain muss im CCP existieren.');
    }

    public function deleteZone(string $zoneId): void
    {
        throw new CapabilityException('Netcup CCP DNS kann keine Zonen löschen; verwalte die Domain im CCP.');
    }

    public function listRecords(string $zoneId): array
    {
        $this->assertAllowedZone($zoneId);
        $records = [];
        foreach ($this->client->listDnsRecords($zoneId) as $row) {
            $record = $this->mapRecord($zoneId, $row);
            if ($record !== null) {
                $records[] = $record;
            }
        }
        return $records;
    }

    public function createRecord(Record $record): Record
    {
        $this->assertAllowedZone($record->zoneId);
        $raw   = $this->client->listDnsRecords($record->zoneId);
        $raw[] = $this->toApiRecord($record);
        $this->client->replaceDnsRecords($record->zoneId, $raw);
        return $this->withId($record);
    }

    public function updateRecord(Record $record): Record
    {
        $this->assertAllowedZone($record->zoneId);
        [$name, $type, $hash] = $this->parseRecordId($record->zoneId, $record->id);
        $raw                  = $this->client->listDnsRecords($record->zoneId);
        $replaced             = false;
        foreach ($raw as $index => $row) {
            $mapped = $this->mapRecord($record->zoneId, $row);
            if ($mapped !== null && $mapped->name === $name && $mapped->type->value === $type && self::contentHash($mapped->content) === $hash) {
                $raw[$index] = $this->toApiRecord($record);
                $replaced    = true;
                break;
            }
        }
        if (!$replaced) {
            throw new NetcupApiException('Der zu aktualisierende Netcup-Record wurde nicht gefunden.');
        }
        $this->client->replaceDnsRecords($record->zoneId, $raw);
        return $this->withId($record);
    }

    public function deleteRecord(string $zoneId, string $recordId): void
    {
        $this->assertAllowedZone($zoneId);
        [$name, $type, $hash] = $this->parseRecordId($zoneId, $recordId);
        $raw                  = $this->client->listDnsRecords($zoneId);
        $remaining            = array_values(array_filter($raw, function (array $row) use ($zoneId, $name, $type, $hash): bool {
            $mapped = $this->mapRecord($zoneId, $row);
            return $mapped === null || $mapped->name !== $name || $mapped->type->value !== $type || self::contentHash($mapped->content) !== $hash;
        }));
        if (count($raw) === count($remaining)) {
            throw new NetcupApiException('Der zu löschende Netcup-Record wurde nicht gefunden.');
        }
        $this->client->replaceDnsRecords($zoneId, $remaining);
    }

    public function replaceRrset(Rrset $rrset): Rrset
    {
        $this->assertAllowedZone($rrset->zoneId);
        $type = RecordType::tryFrom($rrset->type->presentation);
        if ($type === null) {
            throw new CapabilityException('Netcup-Schreiben unbekannter RFC-3597-Typen wird nicht unterstützt.');
        }
        $raw = $this->client->listDnsRecords($rrset->zoneId);
        $remaining = array_values(array_filter($raw, function (array $row) use ($rrset, $type): bool {
            $record = $this->mapRecord($rrset->zoneId, $row);
            return !$record instanceof Record || $record->type !== $type || strcasecmp(rtrim($record->name, '.'), rtrim($rrset->ownerName, '.')) !== 0;
        }));
        foreach (array_values(array_unique($rrset->rdata)) as $rdata) {
            $remaining[] = $this->toApiRecord(new Record('', $rrset->zoneId, $rrset->ownerName, $type, $rrset->ttl, $rdata));
        }
        $this->client->replaceDnsRecords($rrset->zoneId, $remaining);
        foreach ($this->listRrsets($rrset->zoneId) as $observed) {
            if ($observed->type->equals($rrset->type) && strcasecmp(rtrim($observed->ownerName, '.'), rtrim($rrset->ownerName, '.')) === 0) {
                return $observed;
            }
        }
        throw new NetcupApiException('Netcup lieferte das geschriebene RRset nicht zurück.');
    }

    public function deleteRrset(string $zoneId, string $ownerName, string $type): void
    {
        $this->assertAllowedZone($zoneId);
        $raw = $this->client->listDnsRecords($zoneId);
        $remaining = array_values(array_filter($raw, function (array $row) use ($zoneId, $ownerName, $type): bool {
            $record = $this->mapRecord($zoneId, $row);
            return !$record instanceof Record || $record->type->value !== $type || strcasecmp(rtrim($record->name, '.'), rtrim($ownerName, '.')) !== 0;
        }));
        $this->client->replaceDnsRecords($zoneId, $remaining);
    }

    public function getDnssecProfile(string $zoneId): DnssecProfile
    {
        $this->assertAllowedZone($zoneId);
        return new DnssecProfile($zoneId, DnssecState::UNKNOWN, ['auto_managed' => false, 'ds_available' => false]);
    }

    public function executeDnssecAction(string $zoneId, string $action, array $payload = []): DnssecProfile
    {
        throw new CapabilityException('Netcup CCP DNS stellt keine DNSSEC-Key-Verwaltung über diese Schnittstelle bereit.');
    }

    /** @param array<string, mixed> $row */
    private function mapRecord(string $zoneId, array $row): ?Record
    {
        $type = RecordType::tryFrom(strtoupper((string) ($row['type'] ?? '')));
        if ($type === null) {
            return null;
        }
        $name        = (string) ($row['hostname'] ?? '@');
        $name        = $name === '@' ? '' : rtrim($name, '.');
        $destination = trim((string) ($row['destination'] ?? ''));
        $priority    = (string) ($row['priority'] ?? '');
        $content     = in_array($type, [RecordType::MX, RecordType::SRV], true) && $priority !== ''
            ? $priority . ' ' . $destination
            : $destination;
        return new Record(
            $this->buildRecordId($zoneId, $name, $type, $content),
            $zoneId,
            $name,
            $type,
            (int) ($row['ttl'] ?? 3600),
            $content,
        );
    }

    /** @return array<string, mixed> */
    private function toApiRecord(Record $record): array
    {
        $destination = $record->content;
        $priority    = null;
        if (in_array($record->type, [RecordType::MX, RecordType::SRV], true) && preg_match('/^(\d+)\s+(.+)$/', $record->content, $matches) === 1) {
            $priority    = (int) $matches[1];
            $destination = $matches[2];
        }
        $row = [
            'hostname'    => $record->name === '' ? '@' : $record->name,
            'type'        => $record->type->value,
            'destination' => $destination,
            'ttl'         => $record->ttl,
        ];
        if ($priority !== null) {
            $row['priority'] = $priority;
        }
        return $row;
    }

    private function withId(Record $record): Record
    {
        return new Record($this->buildRecordId($record->zoneId, $record->name, $record->type, $record->content), $record->zoneId, $record->name, $record->type, $record->ttl, $record->content, $record->comment, $record->metadata);
    }

    private function buildRecordId(string $zone, string $name, RecordType $type, string $content): string
    {
        return sprintf('%s|%s|%s|%s', $zone, $name === '' ? '@' : $name, $type->value, self::contentHash($content));
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function parseRecordId(string $zone, string $id): array
    {
        $parts = explode('|', $id);
        if (count($parts) !== 4 || $parts[0] !== $zone) {
            throw new \InvalidArgumentException(sprintf('Ungültige Netcup-Record-ID: "%s"', $id));
        }
        return [$parts[1] === '@' ? '' : $parts[1], $parts[2], $parts[3]];
    }

    private function assertAllowedZone(string $zone): void
    {
        if (!in_array(rtrim($zone, '.'), $this->zones, true)) {
            throw new NetcupApiException(sprintf('Netcup-Zone "%s" ist nicht im konfigurierten Zone-Allowlist.', $zone));
        }
    }
}

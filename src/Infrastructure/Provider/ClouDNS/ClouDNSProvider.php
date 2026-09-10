<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Provider\ClouDNS;

use TowerDNS\Application\Contracts\Capability;
use TowerDNS\Application\Exception\CapabilityException;
use TowerDNS\Application\Exception\ProviderRequestException;
use TowerDNS\Domain\DNS\DnssecProfile;
use TowerDNS\Domain\DNS\Record;
use TowerDNS\Domain\DNS\RecordType;
use TowerDNS\Domain\DNS\Zone;
use TowerDNS\Infrastructure\Provider\AbstractDnsProvider;

final class ClouDNSProvider extends AbstractDnsProvider
{
    public const string ID = 'cloudns';

    public function __construct(private readonly ClouDNSApiClient $client)
    {
        parent::__construct();
    }

    public function id(): string { return self::ID; }

    public function displayName(): string { return 'ClouDNS'; }

    protected function capabilityMap(): array
    {
        return [
            Capability::ZONE_LIST => true, Capability::ZONE_READ => true,
            Capability::ZONE_CREATE => true, Capability::ZONE_DELETE => true,
            Capability::RECORD_LIST => true, Capability::RECORD_CREATE => true,
            Capability::RECORD_UPDATE => true, Capability::RECORD_DELETE => true,
            Capability::PROVIDER_CREDENTIALS_MANAGE => true,
        ];
    }

    public function listZones(): array
    {
        return array_map($this->mapZone(...), $this->client->listAll('list-zones'));
    }

    public function createZone(string $zoneName): Zone
    {
        $zoneName = rtrim($zoneName, '.');
        $this->client->request('register', ['domain-name' => $zoneName, 'zone-type' => 'master']);
        return $this->mapZone(['name' => $zoneName, 'type' => 'master']);
    }

    public function deleteZone(string $zoneId): void
    {
        $this->client->request('delete', ['domain-name' => $zoneId]);
    }

    public function listRecords(string $zoneId): array
    {
        $records = [];
        foreach ($this->client->listAll('records', ['domain-name' => $zoneId, 'include-notes' => 1]) as $row) {
            $type = RecordType::tryFrom(strtoupper((string) ($row['type'] ?? '')));
            if ($type === null) {
                continue;
            }
            $content = (string) ($row['record'] ?? '');
            $fields = $this->structuredFields($type);
            if ($type === RecordType::CAA) {
                $content = (string) ($row['caa_flag'] ?? 0) . ' ' . (string) ($row['caa_type'] ?? 'issue') . ' "' . (string) ($row['caa_value'] ?? '') . '"';
            } elseif ($fields !== []) {
                $content = implode(' ', array_map(static fn(string $field): string => (string) ($row[$field] ?? 0), $fields)) . ' ' . $content;
            }
            $records[] = new Record((string) $row['id'], $zoneId, $this->relativeName((string) ($row['host'] ?? ''), $zoneId), $type, (int) ($row['ttl'] ?? 3600), $content,
                isset($row['note']) ? (string) $row['note'] : null);
        }
        return $records;
    }

    public function createRecord(Record $record): Record
    {
        $data = $this->client->request('add-record', ['domain-name' => $record->zoneId, 'record-type' => $record->type->value] + $this->payload($record));
        $id = (string) ($data['data']['id'] ?? $data['id'] ?? '');
        if ($id === '') {
            throw new ProviderRequestException('ClouDNS did not return the new record ID.');
        }
        return $this->withId($record, $id);
    }

    public function updateRecord(Record $record): Record
    {
        $payload = $this->payload($record);
        $existing = $this->client->request('get-record', ['domain-name' => $record->zoneId, 'record-id' => $record->id]);
        if (($existing['type'] ?? '') !== $record->type->value) {
            throw new CapabilityException('ClouDNS cannot change a record type; create a new record instead.');
        }
        // GeoDNS and failover records require controls absent from this editor.
        if (!empty($existing['geodns-location']) || !empty($existing['geodns_location']) || !empty($existing['failover'])) {
            throw new CapabilityException('This ClouDNS record has advanced settings that cannot be edited here.');
        }
        if (isset($existing['status'])) {
            $payload['status'] = (int) $existing['status'];
        }
        $this->client->request('mod-record', ['domain-name' => $record->zoneId, 'record-id' => $record->id] + $payload);
        return $this->withId($record, $record->id);
    }

    public function deleteRecord(string $zoneId, string $recordId): void
    {
        $this->client->request('delete-record', ['domain-name' => $zoneId, 'record-id' => $recordId]);
    }

    public function getDnssecProfile(string $zoneId): DnssecProfile
    {
        throw new CapabilityException('ClouDNS DNSSEC management is not implemented.');
    }

    public function executeDnssecAction(string $zoneId, string $action, array $payload = []): DnssecProfile
    {
        throw new CapabilityException('ClouDNS DNSSEC management is not implemented.');
    }

    /** @param array<string, mixed> $row */
    private function mapZone(array $row): Zone
    {
        $name = (string) ($row['name'] ?? '');
        return new Zone($name, $name, self::ID, (bool) ($row['status'] ?? true), metadata: ['type' => (string) ($row['type'] ?? 'unknown')]);
    }

    private function relativeName(string $name, string $zone): string
    {
        $name = rtrim($name, '.');
        $zone = rtrim($zone, '.');
        if ($name === '@' || strcasecmp($name, $zone) === 0) {
            return '';
        }
        return str_ends_with(strtolower($name), '.' . strtolower($zone)) ? substr($name, 0, -strlen($zone) - 1) : $name;
    }

    /** @return list<string> */
    private function structuredFields(RecordType $type): array
    {
        return match ($type) {
            RecordType::MX => ['priority'],
            RecordType::SRV => ['priority', 'weight', 'port'],
            RecordType::TLSA => ['tlsa_usage', 'tlsa_selector', 'tlsa_matching_type'],
            RecordType::DS => ['key_tag', 'algorithm', 'digest_type'],
            default => [],
        };
    }

    /** @return array<string, scalar> */
    private function payload(Record $record): array
    {
        if (in_array($record->type, [RecordType::SOA, RecordType::DNSKEY, RecordType::RRSIG, RecordType::NSEC], true)) {
            throw new CapabilityException('ClouDNS cannot edit this record type using the record API.');
        }
        $data = ['host' => $this->relativeName($record->name, $record->zoneId), 'record' => $record->content, 'ttl' => $record->ttl];
        $fields = $this->structuredFields($record->type);
        if ($record->type === RecordType::CAA) {
            if (!preg_match('/^(\d+)\s+(\w+)\s+"(.*)"$/sD', trim($record->content), $parts)) {
                throw new ProviderRequestException('Invalid CAA record content.');
            }
            unset($data['record']);
            $data += ['caa_flag' => (int) $parts[1], 'caa_type' => $parts[2], 'caa_value' => $parts[3]];
        } elseif ($fields !== []) {
            $parts = preg_split('/\s+/', trim($record->content), count($fields) + 1);
            if ($parts === false || count($parts) !== count($fields) + 1) {
                throw new ProviderRequestException('Invalid structured DNS record content.');
            }
            foreach ($fields as $index => $field) {
                if (!ctype_digit($parts[$index])) {
                    throw new ProviderRequestException('Invalid numeric DNS record field.');
                }
                $data[$field] = (int) $parts[$index];
            }
            $data['record'] = $parts[count($fields)];
        }
        return $data;
    }

    private function withId(Record $record, string $id): Record
    {
        return new Record($id, $record->zoneId, $this->relativeName($record->name, $record->zoneId), $record->type, $record->ttl, $record->content, $record->comment, $record->metadata);
    }
}

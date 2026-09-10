<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Provider\Ovh;

use GuzzleHttp\Exception\GuzzleException;
use Ovh\Api;
use TowerDNS\Application\Contracts\Capability;
use TowerDNS\Application\Exception\CapabilityException;
use TowerDNS\Application\Exception\ProviderRequestException;
use TowerDNS\Domain\DNS\DnssecProfile;
use TowerDNS\Domain\DNS\DnssecState;
use TowerDNS\Domain\DNS\Record;
use TowerDNS\Domain\DNS\RecordType;
use TowerDNS\Domain\DNS\Zone;
use TowerDNS\Infrastructure\Provider\AbstractDnsProvider;

/**
 * DNS-only adapter using the official OVH SDK for signing and transport.
 *
 * @see https://api.ovh.com/1.0/domain.json
 * @see https://github.com/ovh/php-ovh
 */
final class OvhProvider extends AbstractDnsProvider
{
    public const string ID = 'ovh';

    public function __construct(private readonly Api $client)
    {
        parent::__construct();
    }

    public function id(): string
    {
        return self::ID;
    }

    public function displayName(): string
    {
        return 'OVHcloud';
    }

    protected function capabilityMap(): array
    {
        return [
            Capability::ZONE_LIST => true,
            Capability::ZONE_READ => true,
            Capability::RECORD_LIST => true,
            Capability::RECORD_CREATE => true,
            Capability::RECORD_UPDATE => true,
            Capability::RECORD_DELETE => true,
            Capability::DNSSEC_STATUS_READ => true,
            Capability::PROVIDER_CREDENTIALS_MANAGE => true,
        ];
    }

    public function listZones(): array
    {
        $zones = [];
        foreach ((array) $this->request('GET', '/domain/zone') as $name) {
            $name = (string) $name;
            $status = (array) $this->request('GET', $this->zonePath($name) . '/status');
            $zones[] = new Zone($name, $name, self::ID, (bool) ($status['isDeployed'] ?? false));
        }
        return $zones;
    }

    public function createZone(string $zoneName): Zone
    {
        throw new CapabilityException('OVH DNS-Zonen bitte im OVHcloud-Konto bereitstellen.');
    }

    public function deleteZone(string $zoneId): void
    {
        throw new CapabilityException('OVH DNS-Zonen werden nicht über TowerDNS gekündigt.');
    }

    public function listRecords(string $zoneId): array
    {
        $records = [];
        foreach ((array) $this->request('GET', $this->zonePath($zoneId) . '/record') as $id) {
            $row = (array) $this->request('GET', $this->recordPath($zoneId, (string) $id));
            if (RecordType::tryFrom((string) ($row['fieldType'] ?? '')) === null) {
                continue;
            }
            $records[] = $this->mapRecord($zoneId, $row);
        }
        return $records;
    }

    public function createRecord(Record $record): Record
    {
        $this->assertWritable($record);
        $row = (array) $this->request('POST', $this->zonePath($record->zoneId) . '/record', [
            'fieldType' => $record->type->value,
            ...$this->recordPayload($record),
        ]);
        $this->refresh($record->zoneId);
        return $this->mapRecord($record->zoneId, $row);
    }

    public function updateRecord(Record $record): Record
    {
        $this->assertWritable($record);
        $path = $this->recordPath($record->zoneId, $record->id);
        $old = (array) $this->request('GET', $path);
        if (($old['fieldType'] ?? '') !== $record->type->value) {
            throw new CapabilityException('OVH erlaubt keinen Typwechsel bestehender Records; bitte einen neuen Record erstellen.');
        }
        // Native record IDs ensure siblings and unsupported record types stay untouched.
        $this->request('PUT', $path, $this->recordPayload($record));
        $this->refresh($record->zoneId);
        return $this->mapRecord($record->zoneId, (array) $this->request('GET', $path));
    }

    public function deleteRecord(string $zoneId, string $recordId): void
    {
        $this->request('DELETE', $this->recordPath($zoneId, $recordId));
        $this->refresh($zoneId);
    }

    public function getDnssecProfile(string $zoneId): DnssecProfile
    {
        $row = (array) $this->request('GET', $this->zonePath($zoneId) . '/dnssec');
        $status = (string) ($row['status'] ?? '');
        return new DnssecProfile($zoneId, match ($status) {
            'enabled' => DnssecState::SIGNED,
            'disabled' => DnssecState::UNSIGNED,
            'enableInProgress', 'disableInProgress' => DnssecState::PARTIAL,
            default => DnssecState::UNKNOWN,
        }, metadata: ['status' => $status]);
    }

    public function executeDnssecAction(string $zoneId, string $action, array $payload = []): DnssecProfile
    {
        throw new CapabilityException('OVH DNSSEC kann in TowerDNS nur gelesen werden.');
    }

    private function assertWritable(Record $record): void
    {
        if (!in_array($record->type, [RecordType::A, RecordType::AAAA, RecordType::CAA,
            RecordType::CNAME, RecordType::MX, RecordType::NS, RecordType::PTR,
            RecordType::SRV, RecordType::TLSA, RecordType::TXT], true)) {
            throw new CapabilityException('Dieser Record-Typ wird von der OVH Record-API nicht unterstützt.');
        }
        if ($record->comment !== null && $record->comment !== '') {
            throw new CapabilityException('OVH unterstützt keine Record-Kommentare.');
        }
    }

    /** @return array{subDomain: string, target: string, ttl: int} */
    private function recordPayload(Record $record): array
    {
        return ['subDomain' => $record->name === '@' ? '' : $record->name,
            'target' => $record->content, 'ttl' => $record->ttl];
    }

    /** @param array<string, mixed> $row */
    private function mapRecord(string $zoneId, array $row): Record
    {
        $type = RecordType::tryFrom((string) ($row['fieldType'] ?? ''));
        if ($type === null || !isset($row['id'], $row['target'])) {
            throw new ProviderRequestException('OVH lieferte einen ungültigen DNS-Record.');
        }
        $ttl = (int) ($row['ttl'] ?? 0);
        if ($ttl === 0) {
            $soa = (array) $this->request('GET', $this->zonePath($zoneId) . '/soa');
            $ttl = (int) ($soa['ttl'] ?? 0);
        }
        return new Record((string) $row['id'], $zoneId, (string) ($row['subDomain'] ?? ''),
            $type, $ttl, (string) $row['target']);
    }

    private function zonePath(string $zoneId): string
    {
        return '/domain/zone/' . rawurlencode(rtrim($zoneId, '.'));
    }

    private function recordPath(string $zoneId, string $recordId): string
    {
        if ($recordId === '' || !ctype_digit($recordId)) {
            throw new ProviderRequestException('Ungültige OVH Record-ID.');
        }
        return $this->zonePath($zoneId) . '/record/' . $recordId;
    }

    private function refresh(string $zoneId): void
    {
        try {
            $this->request('POST', $this->zonePath($zoneId) . '/refresh');
        } catch (ProviderRequestException $e) {
            throw new ProviderRequestException('OVH hat die Änderung gespeichert, aber die Veröffentlichung der Zone ist fehlgeschlagen. Bitte die Zone bei OVH aktualisieren.', previous: $e);
        }
    }

    /** @param array<string, scalar>|null $payload */
    private function request(string $method, string $path, ?array $payload = null): mixed
    {
        try {
            return match ($method) {
                'GET' => $this->client->get($path),
                'POST' => $this->client->post($path, $payload),
                'PUT' => $this->client->put($path, $payload ?? []),
                'DELETE' => $this->client->delete($path),
                default => throw new \LogicException('Unsupported OVH API method.'),
            };
        } catch (GuzzleException | \JsonException $e) {
            // Avoid exposing signed request headers or response bodies containing credentials.
            throw new ProviderRequestException('OVH DNS-API-Anfrage fehlgeschlagen (' . $method . ').', previous: $e);
        }
    }
}

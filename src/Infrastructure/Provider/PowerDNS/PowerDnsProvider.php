<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Provider\PowerDNS;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
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
 * Adapter for the PowerDNS Authoritative Server HTTP API.
 *
 * PowerDNS exposes deep, imperative DNSSEC controls (cryptokeys endpoint),
 * so this adapter binds DNSSEC actions directly to the native PDNS API as
 * required by the project specification.
 *
 * @see https://doc.powerdns.com/authoritative/http-api/
 */
final class PowerDnsProvider extends AbstractDnsProvider
{
    public const ID = 'powerdns';

    private ClientInterface $http;

    public function __construct(
        string $baseUrl,
        private readonly string $apiKey,
        private readonly string $serverId = 'localhost',
        ?ClientInterface $http = null,
    ) {
        if ($baseUrl === '' || $this->apiKey === '') {
            throw new ProviderRequestException('PowerDNS-Adapter benoetigt Basis-URL und API-Key.');
        }

        $this->http = $http ?? new Client([
            'base_uri'    => rtrim($baseUrl, '/') . '/',
            'timeout'     => 30,
            'http_errors' => true,
        ]);

        parent::__construct();
    }

    public function id(): string
    {
        return self::ID;
    }

    public function displayName(): string
    {
        return 'PowerDNS';
    }

    protected function capabilityMap(): array
    {
        return [
            Capability::ZONE_LIST   => true,
            Capability::ZONE_READ   => true,
            Capability::ZONE_CREATE => true,
            Capability::ZONE_DELETE => true,
            Capability::ZONE_UPDATE => true,

            Capability::RECORD_LIST    => true,
            Capability::RECORD_CREATE  => true,
            Capability::RECORD_UPDATE  => true,
            Capability::RECORD_DELETE  => true,
            Capability::RECORD_COMMENT => true,

            Capability::DNSSEC_STATUS_READ    => true,
            Capability::DNSSEC_AUTO_MANAGED   => false,
            Capability::DNSSEC_ACTION_EXECUTE => true,
            Capability::DNSSEC_KEY_LIST       => true,
            Capability::DNSSEC_KEY_ROLLOVER   => true,
            Capability::DNSSEC_DS_READ        => true,

            Capability::PROVIDER_CREDENTIALS_MANAGE => true,
        ];
    }

    public function listZones(): array
    {
        $rows = $this->request('GET', $this->serverPath('zones'));
        $zones = [];
        foreach ((array) $rows as $row) {
            $zones[] = $this->mapZone((array) $row);
        }
        return $zones;
    }

    public function createZone(string $zoneName): Zone
    {
        $canonical = rtrim($zoneName, '.') . '.';
        $row = $this->request('POST', $this->serverPath('zones'), [
            'name' => $canonical,
            'kind' => 'Native',
            'nameservers' => [],
        ]);
        return $this->mapZone((array) $row);
    }

    public function deleteZone(string $zoneId): void
    {
        $this->request('DELETE', $this->serverPath('zones/' . rawurlencode($zoneId)), null, false);
    }

    public function listRecords(string $zoneId): array
    {
        $row = (array) $this->request('GET', $this->serverPath('zones/' . rawurlencode($zoneId)));
        $records = [];
        foreach ((array) ($row['rrsets'] ?? []) as $rrset) {
            $rrset = (array) $rrset;
            $type = RecordType::tryFrom(strtoupper((string) ($rrset['type'] ?? '')));
            if ($type === null) {
                continue;
            }
            $name = (string) ($rrset['name'] ?? '');
            $ttl  = (int) ($rrset['ttl'] ?? 3600);
            foreach ((array) ($rrset['records'] ?? []) as $r) {
                $r = (array) $r;
                $records[] = new Record(
                    id: sprintf('%s|%s|%s', $zoneId, rtrim($name, '.'), $type->value),
                    zoneId: $zoneId,
                    name: rtrim($name, '.'),
                    type: $type,
                    ttl: $ttl,
                    content: (string) ($r['content'] ?? ''),
                );
            }
        }
        return $records;
    }

    public function createRecord(Record $record): Record
    {
        $this->patchRrset($record->zoneId, $record, 'REPLACE');
        return $record;
    }

    public function updateRecord(Record $record): Record
    {
        $this->patchRrset($record->zoneId, $record, 'REPLACE');
        return $record;
    }

    public function deleteRecord(string $zoneId, string $recordId): void
    {
        [$name, $type] = $this->parseRecordId($zoneId, $recordId);
        $this->request('PATCH', $this->serverPath('zones/' . rawurlencode($zoneId)), [
            'rrsets' => [[
                'name'       => $name . '.',
                'type'       => $type,
                'changetype' => 'DELETE',
            ]],
        ], false);
    }

    public function getDnssecProfile(string $zoneId): DnssecProfile
    {
        $zone = (array) $this->request('GET', $this->serverPath('zones/' . rawurlencode($zoneId)));
        $keys = (array) $this->request('GET', $this->serverPath('zones/' . rawurlencode($zoneId) . '/cryptokeys'));

        $signed = (bool) ($zone['dnssec'] ?? false);
        $state  = $signed ? DnssecState::SIGNED : DnssecState::UNSIGNED;

        return new DnssecProfile(
            zoneId: $zoneId,
            state: $state,
            features: [
                'auto_managed' => false,
                'native_keys'  => true,
                'has_keys'     => $keys !== [],
            ],
            metadata: [
                'serial'    => isset($zone['serial']) ? (int) $zone['serial'] : null,
                'key_count' => count($keys),
            ],
        );
    }

    public function executeDnssecAction(string $zoneId, string $action, array $payload = []): DnssecProfile
    {
        $base = $this->serverPath('zones/' . rawurlencode($zoneId));
        switch ($action) {
            case 'enable':
                $this->request('PUT', $base, ['dnssec' => true], false);
                break;
            case 'disable':
                $this->request('PUT', $base, ['dnssec' => false], false);
                break;
            case 'key.add':
                $this->request('POST', $base . '/cryptokeys', $payload, false);
                break;
            case 'key.remove':
                $keyId = (string) ($payload['id'] ?? '');
                if ($keyId === '') {
                    throw new ProviderRequestException('PowerDNS key.remove benoetigt "id" im Payload.');
                }
                $this->request('DELETE', $base . '/cryptokeys/' . rawurlencode($keyId), null, false);
                break;
            case 'key.activate':
            case 'key.deactivate':
                $keyId = (string) ($payload['id'] ?? '');
                if ($keyId === '') {
                    throw new ProviderRequestException('PowerDNS ' . $action . ' benoetigt "id" im Payload.');
                }
                $this->request('PUT', $base . '/cryptokeys/' . rawurlencode($keyId), [
                    'active' => $action === 'key.activate',
                ], false);
                break;
            default:
                throw new CapabilityException(sprintf('PowerDNS kennt die DNSSEC-Aktion "%s" nicht.', $action));
        }

        return $this->getDnssecProfile($zoneId);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapZone(array $row): Zone
    {
        $id   = (string) ($row['id'] ?? $row['name'] ?? '');
        $name = rtrim((string) ($row['name'] ?? $id), '.');
        return new Zone(
            id: $id,
            name: $name,
            providerId: $this->id(),
            active: true,
            metadata: [
                'kind'   => (string) ($row['kind'] ?? ''),
                'serial' => isset($row['serial']) ? (int) $row['serial'] : null,
                'dnssec' => (bool) ($row['dnssec'] ?? false),
            ],
        );
    }

    private function patchRrset(string $zoneId, Record $record, string $changetype): void
    {
        $this->request('PATCH', $this->serverPath('zones/' . rawurlencode($zoneId)), [
            'rrsets' => [[
                'name'       => rtrim($record->name, '.') . '.',
                'type'       => $record->type->value,
                'ttl'        => $record->ttl,
                'changetype' => $changetype,
                'records'    => [[
                    'content'  => $record->content,
                    'disabled' => false,
                ]],
            ]],
        ], false);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function parseRecordId(string $zoneId, string $recordId): array
    {
        $parts = explode('|', $recordId);
        if (count($parts) !== 3 || $parts[0] !== $zoneId) {
            throw new ProviderRequestException('Ungueltige PowerDNS-Record-ID.');
        }
        return [$parts[1], $parts[2]];
    }

    private function serverPath(string $suffix): string
    {
        return sprintf('api/v1/servers/%s/%s', rawurlencode($this->serverId), $suffix);
    }

    /**
     * @param array<string, mixed>|null $data
     * @return mixed
     */
    private function request(string $method, string $endpoint, ?array $data = null, bool $decode = true): mixed
    {
        try {
            $options = [RequestOptions::HEADERS => ['X-API-Key' => $this->apiKey, 'Accept' => 'application/json']];
            if ($data !== null) {
                $options[RequestOptions::JSON] = $data;
            }
            $response = $this->http->request($method, $endpoint, $options);
        } catch (GuzzleException $e) {
            throw new ProviderRequestException('PowerDNS API-Aufruf fehlgeschlagen: ' . $e->getMessage(), (int) $e->getCode(), $e);
        }

        if (!$decode) {
            return null;
        }

        $body = $response->getBody()->getContents();
        if ($body === '') {
            return [];
        }
        return json_decode($body, true);
    }
}

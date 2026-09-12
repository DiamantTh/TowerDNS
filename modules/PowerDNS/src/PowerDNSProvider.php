<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Module\PowerDNS;

use GuzzleHttp\ClientInterface;
use TowerDNS\Application\Contracts\Capability;
use TowerDNS\Application\Contracts\ProviderConstraintProfile;
use TowerDNS\Application\Exception\CapabilityException;
use TowerDNS\Application\Exception\ProviderRequestException;
use TowerDNS\Domain\DNS\DnsRecordType;
use TowerDNS\Domain\DNS\DnssecProfile;
use TowerDNS\Domain\DNS\DnssecState;
use TowerDNS\Domain\DNS\Record;
use TowerDNS\Domain\DNS\RecordType;
use TowerDNS\Domain\DNS\Rrset;
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
final class PowerDNSProvider extends AbstractDnsProvider
{
    public const string ID = 'powerdns';

    private readonly PowerDNSAPIClient $client;

    public function __construct(
        string $baseUrl,
        string $apiKey,
        string $serverId = 'localhost',
        ?ClientInterface $http = null,
    ) {
        $this->client = new PowerDNSAPIClient($baseUrl, $apiKey, $serverId, $http);
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

    public function constraints(): ProviderConstraintProfile
    {
        return new ProviderConstraintProfile(
            $this->client->supportsExtend() ? 'atomic_extend' : 'atomic_replace',
            'immediate',
            true,
            ['server_id' => $this->client->serverId()],
        );
    }

    public function listZones(): array
    {
        $rows  = $this->client->request('GET', $this->client->serverPath('zones'));
        $zones = [];
        foreach ((array) $rows as $row) {
            $zones[] = $this->mapZone((array) $row);
        }
        return $zones;
    }

    public function createZone(string $zoneName): Zone
    {
        $canonical = rtrim($zoneName, '.') . '.';
        $row       = $this->client->request('POST', $this->client->serverPath('zones'), [
            'name'        => $canonical,
            'kind'        => 'Native',
            'nameservers' => [],
            'api_rectify' => true,
        ]);
        return $this->mapZone((array) $row);
    }

    public function deleteZone(string $zoneId): void
    {
        $this->client->request('DELETE', $this->client->serverPath('zones/' . rawurlencode($zoneId)), null, false);
    }

    public function listRecords(string $zoneId): array
    {
        $row     = (array) $this->client->request('GET', $this->client->serverPath('zones/' . rawurlencode($zoneId)));
        $records = [];
        foreach ((array) ($row['rrsets'] ?? []) as $rrset) {
            $rrset = (array) $rrset;
            $type  = RecordType::tryFrom(strtoupper((string) ($rrset['type'] ?? '')));
            if ($type === null) {
                continue;
            }
            $name = $this->fromFqdn((string) ($rrset['name'] ?? ''), $zoneId);
            $ttl  = (int) ($rrset['ttl'] ?? 3600);
            foreach ((array) ($rrset['records'] ?? []) as $r) {
                $r         = (array) $r;
                $content   = (string) ($r['content'] ?? '');
                $records[] = new Record(
                    id: $this->buildRecordId($zoneId, $name, $type, $content),
                    zoneId: $zoneId,
                    name: $name,
                    type: $type,
                    ttl: $ttl,
                    content: $content,
                    comment: ((string) ($r['comment'] ?? '')) ?: null,
                );
            }
        }
        return $records;
    }

    /** @return list<Rrset> */
    public function listRrsets(string $zoneId): array
    {
        $row  = (array) $this->client->request('GET', $this->client->serverPath('zones/' . rawurlencode($zoneId)));
        $sets = [];
        foreach ((array) ($row['rrsets'] ?? []) as $raw) {
            $raw = (array) $raw;
            try {
                $type = DnsRecordType::parse((string) ($raw['type'] ?? ''));
            } catch (\InvalidArgumentException) {
                continue;
            }
            $rdata = [];
            foreach ((array) ($raw['records'] ?? []) as $record) {
                $record  = (array) $record;
                $content = (string) ($record['content'] ?? '');
                if ($content !== '') {
                    $rdata[] = $content;
                }
            }
            if ($rdata !== []) {
                $sets[] = new Rrset($zoneId, $this->fromFqdn((string) ($raw['name'] ?? ''), $zoneId), $type, (int) ($raw['ttl'] ?? 3600), $rdata);
            }
        }
        return $sets;
    }

    public function replaceRrset(Rrset $rrset): Rrset
    {
        $this->patchRrset(
            $rrset->zoneId,
            $rrset->ownerName,
            $rrset->type->presentation,
            $rrset->ttl,
            array_map(static fn(string $rdata): array => ['content' => $rdata, 'disabled' => false], $rrset->rdata),
            'REPLACE'
        );
        return $this->readRrset($rrset);
    }

    public function deleteRrset(string $zoneId, string $ownerName, string $type): void
    {
        $this->patchRrset($zoneId, $ownerName, $type, 0, [], 'DELETE');
    }

    private function readRrset(Rrset $expected): Rrset
    {
        foreach ($this->listRrsets($expected->zoneId) as $rrset) {
            if ($rrset->type->equals($expected->type) && strcasecmp(rtrim($rrset->ownerName, '.'), rtrim($expected->ownerName, '.')) === 0) {
                return $rrset;
            }
        }
        throw new ProviderRequestException('PowerDNS lieferte das geschriebene RRset nicht zurück.');
    }

    public function createRecord(Record $record): Record
    {
        if ($this->client->supportsExtend()) {
            $this->patchRrset(
                $record->zoneId,
                $record->name,
                $record->type->value,
                $record->ttl,
                [['content' => $record->content, 'disabled' => false]],
                'EXTEND',
            );
        } else {
            $rrset    = $this->fetchRRSet($record->zoneId, $record->name, $record->type->value);
            $contents = $rrset['contents'];
            if (!in_array($record->content, $contents, true)) {
                $contents[] = $record->content;
            }
            $this->patchRrset(
                $record->zoneId,
                $record->name,
                $record->type->value,
                $record->ttl,
                array_map(static fn(string $c): array => ['content' => $c, 'disabled' => false], $contents),
                'REPLACE',
            );
        }

        return new Record(
            id: $this->buildRecordId($record->zoneId, $record->name, $record->type, $record->content),
            zoneId: $record->zoneId,
            name: $record->name,
            type: $record->type,
            ttl: $record->ttl,
            content: $record->content,
            comment: $record->comment,
            metadata: $record->metadata,
        );
    }

    public function updateRecord(Record $record): Record
    {
        [$name, $typeStr, $oldHash] = $this->parseRecordId($record->zoneId, $record->id);

        $rrset   = $this->fetchRRSet($record->zoneId, $name, $typeStr);
        $updated = array_map(
            fn(string $c): string => self::contentHash($c) === $oldHash ? $record->content : $c,
            $rrset['contents'],
        );
        $updated = array_values(array_unique($updated));

        $this->patchRrset(
            $record->zoneId,
            $record->name,
            $record->type->value,
            $record->ttl,
            array_map(static fn(string $c): array => ['content' => $c, 'disabled' => false], $updated),
            'REPLACE',
        );

        return new Record(
            id: $this->buildRecordId($record->zoneId, $record->name, $record->type, $record->content),
            zoneId: $record->zoneId,
            name: $record->name,
            type: $record->type,
            ttl: $record->ttl,
            content: $record->content,
            comment: $record->comment,
            metadata: $record->metadata,
        );
    }

    public function deleteRecord(string $zoneId, string $recordId): void
    {
        [$name, $typeStr, $oldHash] = $this->parseRecordId($zoneId, $recordId);
        $rrset                      = $this->fetchRRSet($zoneId, $name, $typeStr);

        // Find the actual content string by matching the hash.
        $toRemove = null;
        foreach ($rrset['contents'] as $c) {
            if (self::contentHash($c) === $oldHash) {
                $toRemove = $c;
                break;
            }
        }

        if ($toRemove === null) {
            // Record already gone — treat as success.
            return;
        }

        if ($this->client->supportsExtend()) {
            // PRUNE removes specific records without touching the rest.
            $this->patchRrset($zoneId, $name, $typeStr, $rrset['ttl'], [
                ['content' => $toRemove, 'disabled' => false],
            ], 'PRUNE');
            return;
        }

        // Read-modify-write: remove the entry and REPLACE, or DELETE if empty.
        $remaining = array_values(array_filter(
            $rrset['contents'],
            static fn(string $c): bool => $c !== $toRemove,
        ));

        if ($remaining === []) {
            $this->client->request('PATCH', $this->client->serverPath('zones/' . rawurlencode($zoneId)), [
                'rrsets' => [[
                    'name'       => $this->toFqdn($name, $zoneId) . '.',
                    'type'       => strtoupper($typeStr),
                    'changetype' => 'DELETE',
                ]],
            ], false);
        } else {
            $this->patchRrset(
                $zoneId,
                $name,
                $typeStr,
                $rrset['ttl'],
                array_map(static fn(string $c): array => ['content' => $c, 'disabled' => false], $remaining),
                'REPLACE',
            );
        }
    }

    public function getDnssecProfile(string $zoneId): DnssecProfile
    {
        $zone = (array) $this->client->request('GET', $this->client->serverPath('zones/' . rawurlencode($zoneId)));
        $keys = (array) $this->client->request('GET', $this->client->serverPath('zones/' . rawurlencode($zoneId) . '/cryptokeys'));

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
        $base = $this->client->serverPath('zones/' . rawurlencode($zoneId));
        switch ($action) {
            case 'enable':
                $this->client->request('PUT', $base, ['dnssec' => true], false);
                break;
            case 'disable':
                $this->client->request('PUT', $base, ['dnssec' => false], false);
                break;
            case 'key.add':
                $this->client->request('POST', $base . '/cryptokeys', $payload, false);
                break;
            case 'key.remove':
                $idVal = $payload['id'] ?? null;
                $keyId = is_scalar($idVal) ? (string) $idVal : '';
                if ($keyId === '') {
                    throw new ProviderRequestException('PowerDNS key.remove benoetigt "id" im Payload.');
                }
                $this->client->request('DELETE', $base . '/cryptokeys/' . rawurlencode($keyId), null, false);
                break;
            case 'key.activate':
            case 'key.deactivate':
                $idVal = $payload['id'] ?? null;
                $keyId = is_scalar($idVal) ? (string) $idVal : '';
                if ($keyId === '') {
                    throw new ProviderRequestException('PowerDNS ' . $action . ' benoetigt "id" im Payload.');
                }
                $this->client->request('PUT', $base . '/cryptokeys/' . rawurlencode($keyId), [
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

    /**
     * @param list<array{content: string, disabled: bool}> $records
     */
    private function patchRrset(
        string $zoneId,
        string $name,
        string $type,
        int $ttl,
        array $records,
        string $changetype,
    ): void {
        $payload = [
            'name'       => $this->toFqdn($name, $zoneId) . '.',
            'type'       => strtoupper($type),
            'changetype' => $changetype,
            'records'    => $records,
        ];
        // TTL is required for write changetypes but not for DELETE/PRUNE.
        if (!in_array($changetype, ['DELETE', 'PRUNE'], true)) {
            $payload['ttl'] = $ttl;
        }
        $this->client->request('PATCH', $this->client->serverPath('zones/' . rawurlencode($zoneId)), [
            'rrsets' => [$payload],
        ], false);
    }

    /**
     * Fetch the contents and TTL of a single RRset from PDNS.
     *
     * PDNS does not expose a per-RRset GET endpoint, so this loads the full
     * zone and filters the matching entry. The result is cached implicitly by
     * Guzzle if the server sends appropriate cache headers.
     *
     * @return array{ttl: int, contents: list<string>}
     */
    private function fetchRRSet(string $zoneId, string $name, string $type): array
    {
        $row    = (array) $this->client->request('GET', $this->client->serverPath('zones/' . rawurlencode($zoneId)));
        $needle = $this->toFqdn($name, $zoneId);
        foreach ((array) ($row['rrsets'] ?? []) as $rrset) {
            $rrset = (array) $rrset;
            if (
                rtrim((string) ($rrset['name'] ?? ''), '.') === $needle && strtoupper((string) ($rrset['type'] ?? '')) === strtoupper($type)
            ) {
                $contents = [];
                foreach ((array) ($rrset['records'] ?? []) as $r) {
                    $r          = (array) $r;
                    $contents[] = (string) ($r['content'] ?? '');
                }
                return ['ttl' => (int) ($rrset['ttl'] ?? 3600), 'contents' => $contents];
            }
        }
        return ['ttl' => 3600, 'contents' => []];
    }

    /**
     * Build a 4-part record ID: zone|name|type|contenthash.
     *
     * The content hash makes each individual record within an RRset
     * uniquely addressable for targeted update and delete operations.
     */
    private function buildRecordId(string $zoneId, string $name, RecordType $type, string $content): string
    {
        return sprintf('%s|%s|%s|%s', $zoneId, rtrim($name, '.'), $type->value, self::contentHash($content));
    }

    /**
     * Parse a 4-part record ID back into its components.
     *
     * @return array{0: string, 1: string, 2: string} [name, type, contentHash]
     */
    private function parseRecordId(string $zoneId, string $recordId): array
    {
        $parts = explode('|', $recordId);
        if (count($parts) !== 4 || $parts[0] !== $zoneId) {
            throw new ProviderRequestException(
                sprintf('Ungueltige PowerDNS-Record-ID: "%s"', $recordId)
            );
        }
        return [$parts[1], $parts[2], $parts[3]];
    }

    /** Convert a relative record name to the FQDN required by the PDNS API. */
    private function toFqdn(string $name, string $zoneId): string
    {
        $name = rtrim($name, '.');
        $zone = rtrim($zoneId, '.');

        if ($name === '' || $name === '@') {
            return $zone;
        }

        if ($name === $zone || str_ends_with($name, '.' . $zone)) {
            return $name;
        }

        return $name . '.' . $zone;
    }

    /** Convert a PDNS FQDN to TowerDNS's relative record-name representation. */
    private function fromFqdn(string $name, string $zoneId): string
    {
        $name = rtrim($name, '.');
        $zone = rtrim($zoneId, '.');

        if ($name === $zone) {
            return '';
        }

        $suffix = '.' . $zone;

        return str_ends_with($name, $suffix) ? substr($name, 0, -strlen($suffix)) : $name;
    }
}

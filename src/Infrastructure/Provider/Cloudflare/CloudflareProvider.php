<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Provider\Cloudflare;

use TowerDNS\Application\Contracts\Capability;
use TowerDNS\Application\Exception\CapabilityException;
use TowerDNS\Domain\DNS\DnssecProfile;
use TowerDNS\Domain\DNS\DnssecState;
use TowerDNS\Domain\DNS\Record;
use TowerDNS\Domain\DNS\RecordType;
use TowerDNS\Domain\DNS\Zone;
use TowerDNS\Infrastructure\Provider\AbstractDnsProvider;

/**
 * Cloudflare provider adapter (Cloudflare v4 REST API).
 *
 * Zone IDs inside TowerDNS are the human-readable domain names (e.g.
 * "example.com"); Cloudflare's internal UUIDs are resolved lazily via the
 * API client and cached per-request.
 *
 * Record IDs are Cloudflare's native UUID strings, so update/delete
 * operations do not require any hash-based ID reconstruction.
 *
 * DNSSEC is managed by Cloudflare automatically when enabled; there is no
 * concept of key management exposed to API clients.
 *
 * @see https://developers.cloudflare.com/api/
 */
final class CloudflareProvider extends AbstractDnsProvider
{
    public const string ID = 'cloudflare';

    private readonly CloudflareApiClient $client;

    public function __construct(string $apiToken)
    {
        $this->client = new CloudflareApiClient($apiToken);
        parent::__construct();
    }

    public function id(): string
    {
        return self::ID;
    }

    public function displayName(): string
    {
        return 'Cloudflare';
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
            Capability::DNSSEC_AUTO_MANAGED   => true,
            Capability::DNSSEC_DS_READ        => true,
            Capability::DNSSEC_ACTION_EXECUTE => true,
            Capability::DNSSEC_KEY_LIST       => false,
            Capability::DNSSEC_KEY_ROLLOVER   => false,

            Capability::PROVIDER_CREDENTIALS_MANAGE => true,
        ];
    }

    // ── Zone operations ───────────────────────────────────────────────────────

    public function listZones(): array
    {
        $zones = [];
        foreach ($this->client->listZones() as $row) {
            $zones[] = $this->mapZone($row);
        }
        return $zones;
    }

    public function createZone(string $zoneName): Zone
    {
        return $this->mapZone($this->client->createZone($zoneName));
    }

    public function deleteZone(string $zoneId): void
    {
        $this->client->deleteZone($zoneId);
    }

    // ── Record operations ─────────────────────────────────────────────────────

    public function listRecords(string $zoneId): array
    {
        $records = [];
        foreach ($this->client->listDnsRecords($zoneId) as $row) {
            $r = $this->mapRecord($zoneId, $row);
            if ($r instanceof Record) {
                $records[] = $r;
            }
        }
        return $records;
    }

    public function createRecord(Record $record): Record
    {
        $name    = $this->toFqdn($record->name, $record->zoneId);
        $payload = [
            'name'    => $name,
            'type'    => $record->type->value,
            'content' => $record->content,
            'ttl'     => $record->ttl === 1 ? 1 : max(60, $record->ttl),
            'proxied' => false,
        ];
        if ($record->comment !== null && $record->comment !== '') {
            $payload['comment'] = $record->comment;
        }

        $row = $this->client->createDnsRecord($record->zoneId, $payload);
        return $this->mapRecord($record->zoneId, $row) ?? $record;
    }

    public function updateRecord(Record $record): Record
    {
        $name    = $this->toFqdn($record->name, $record->zoneId);
        $payload = [
            'name'    => $name,
            'type'    => $record->type->value,
            'content' => $record->content,
            'ttl'     => $record->ttl === 1 ? 1 : max(60, $record->ttl),
            'proxied' => false,
        ];
        if ($record->comment !== null) {
            $payload['comment'] = $record->comment;
        }

        // record.id is the Cloudflare native UUID
        $row = $this->client->updateDnsRecord($record->zoneId, $record->id, $payload);
        return $this->mapRecord($record->zoneId, $row) ?? $record;
    }

    public function deleteRecord(string $zoneId, string $recordId): void
    {
        // recordId is the Cloudflare native UUID
        $this->client->deleteDnsRecord($zoneId, $recordId);
    }

    // ── DNSSEC operations ─────────────────────────────────────────────────────

    public function getDnssecProfile(string $zoneId): DnssecProfile
    {
        $row    = $this->client->getDnssec($zoneId);
        $status = strtolower((string) ($row['status'] ?? 'inactive'));

        $state = match ($status) {
            'active'  => DnssecState::SIGNED,
            'pending' => DnssecState::PARTIAL,
            'disabled',
            'inactive',
            'pending-disabled',
            'pending-inactive' => DnssecState::UNSIGNED,
            default            => DnssecState::UNKNOWN,
        };

        $metadata = [];
        foreach (['algorithm', 'digest', 'digest_type', 'ds', 'flags', 'key_tag', 'key_type', 'public_key'] as $field) {
            if (isset($row[$field])) {
                $metadata[$field] = is_scalar($row[$field]) ? (string) $row[$field] : null;
            }
        }

        return new DnssecProfile(
            zoneId: $zoneId,
            state: $state,
            features: ['auto_managed' => true, 'ds_available' => isset($row['ds'])],
            metadata: $metadata,
        );
    }

    public function executeDnssecAction(string $zoneId, string $action, array $payload = []): DnssecProfile
    {
        $cfStatus = match ($action) {
            'enable'  => 'active',
            'disable' => 'disabled',
            default   => throw new CapabilityException(
                sprintf('Cloudflare kennt die DNSSEC-Aktion "%s" nicht.', $action)
            ),
        };

        $this->client->patchDnssec($zoneId, ['status' => $cfStatus]);
        return $this->getDnssecProfile($zoneId);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $row
     */
    private function mapZone(array $row): Zone
    {
        $name = rtrim((string) ($row['name'] ?? ''), '.');
        return new Zone(
            id: $name,
            name: $name,
            providerId: self::ID,
            active: (string) ($row['status'] ?? '') === 'active',
            metadata: [
                'cf_id'  => (string) ($row['id'] ?? ''),
                'plan'   => (string) ($row['plan']['name'] ?? ''),
                'paused' => (bool) ($row['paused'] ?? false),
            ],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRecord(string $zoneId, array $row): ?Record
    {
        $type = RecordType::tryFrom(strtoupper((string) ($row['type'] ?? '')));
        if ($type === null) {
            return null;
        }

        $fqdn    = rtrim((string) ($row['name'] ?? ''), '.');
        $subname = $this->fromFqdn($fqdn, $zoneId);
        $id      = (string) ($row['id'] ?? '');
        $content = (string) ($row['content'] ?? '');
        $ttl     = (int) ($row['ttl'] ?? 3600);
        $comment = (string) ($row['comment'] ?? '');

        return new Record(
            id: $id !== '' ? $id : self::contentHash($content),
            zoneId: $zoneId,
            name: $subname,
            type: $type,
            ttl: $ttl,
            content: $content,
            comment: $comment !== '' ? $comment : null,
        );
    }

    /**
     * Convert a subname (e.g. "www", "@", "") to a fully-qualified domain name
     * for use in Cloudflare API calls.
     */
    private function toFqdn(string $subname, string $zoneName): string
    {
        $sub = trim($subname, '.');
        if ($sub === '' || $sub === '@') {
            return $zoneName;
        }
        // Already an FQDN?
        if (str_ends_with($sub, '.' . $zoneName) || $sub === $zoneName) {
            return $sub;
        }
        return $sub . '.' . $zoneName;
    }

    /**
     * Strip the zone suffix from a fully-qualified name to get the subname.
     * Returns "" for apex records.
     */
    private function fromFqdn(string $fqdn, string $zoneName): string
    {
        $fqdn = rtrim($fqdn, '.');
        if ($fqdn === $zoneName) {
            return '';
        }
        $suffix = '.' . $zoneName;
        if (str_ends_with($fqdn, $suffix)) {
            return substr($fqdn, 0, -strlen($suffix));
        }
        return $fqdn;
    }
}

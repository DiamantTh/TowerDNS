<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Module\INWX;

use TowerDNS\Application\Contracts\Capability;
use TowerDNS\Application\Exception\CapabilityException;
use TowerDNS\Domain\DNS\DNSSECProfile;
use TowerDNS\Domain\DNS\DNSSECState;
use TowerDNS\Domain\DNS\Record;
use TowerDNS\Domain\DNS\RecordType;
use TowerDNS\Domain\DNS\Rrset;
use TowerDNS\Domain\DNS\Zone;
use TowerDNS\Infrastructure\Provider\AbstractDNSProvider;

/**
 * INWX provider adapter (INWX nameserver JSON-RPC API).
 *
 * INWX exposes a JSON-RPC / XML-RPC API for nameserver and DNSSEC management,
 * including direct DS record submission for registered domains. Zone IDs are
 * the human-readable domain names. Record IDs are INWX integer IDs stored as
 * strings.
 *
 * @see https://www.inwx.de/de/api-documentation
 */
final class INWXProvider extends AbstractDNSProvider
{
    public const string ID = 'inwx';

    private readonly INWXAPIClient $client;

    public function __construct(string $username, string $password)
    {
        $this->client = new INWXAPIClient($username, $password);
        parent::__construct();
    }

    public function id(): string
    {
        return self::ID;
    }

    public function displayName(): string
    {
        return 'INWX';
    }

    protected function capabilityMap(): array
    {
        return [
            Capability::ZONE_LIST   => true,
            Capability::ZONE_READ   => true,
            Capability::ZONE_CREATE => true,
            Capability::ZONE_DELETE => true,
            Capability::ZONE_UPDATE => false,

            Capability::RECORD_LIST    => true,
            Capability::RECORD_CREATE  => true,
            Capability::RECORD_UPDATE  => true,
            Capability::RECORD_DELETE  => true,
            Capability::RECORD_COMMENT => false,

            Capability::DNSSEC_STATUS_READ    => true,
            Capability::DNSSEC_AUTO_MANAGED   => false,
            Capability::DNSSEC_DS_READ        => true,
            Capability::DNSSEC_ACTION_EXECUTE => true,
            Capability::DNSSEC_KEY_LIST       => true,
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
        $this->client->createZone($zoneName);
        return new Zone(
            id: $zoneName,
            name: $zoneName,
            providerId: self::ID,
            active: true,
        );
    }

    public function deleteZone(string $zoneId): void
    {
        $this->client->deleteZone($zoneId);
    }

    // ── Record operations ─────────────────────────────────────────────────────

    public function listRecords(string $zoneId): array
    {
        $info    = $this->client->getZoneInfo($zoneId);
        $records = [];

        foreach ((array) ($info['record'] ?? []) as $row) {
            $r = $this->mapRecord($zoneId, (array) $row);
            if ($r instanceof Record) {
                $records[] = $r;
            }
        }

        return $records;
    }

    public function createRecord(Record $record): Record
    {
        $params = [
            'domain'  => $record->zoneId,
            'type'    => $record->type->value,
            'name'    => $record->name === '' ? '@' : $record->name,
            'content' => $record->content,
            'ttl'     => $record->ttl,
        ];

        $result = $this->client->createRecord($params);
        $newId  = (string) ($result['id'] ?? '');

        return new Record(
            id: $newId !== '' ? $newId : self::contentHash($record->content),
            zoneId: $record->zoneId,
            name: $record->name,
            type: $record->type,
            ttl: $record->ttl,
            content: $record->content,
        );
    }

    public function updateRecord(Record $record): Record
    {
        $inwxId = (int) $record->id;
        if ($inwxId <= 0) {
            throw new INWXAPIException('Ungültige INWX-Record-ID: ' . $record->id);
        }

        $this->client->updateRecord([
            'id'      => $inwxId,
            'type'    => $record->type->value,
            'name'    => $record->name === '' ? '@' : $record->name,
            'content' => $record->content,
            'ttl'     => $record->ttl,
        ]);

        return new Record(
            id: $record->id,
            zoneId: $record->zoneId,
            name: $record->name,
            type: $record->type,
            ttl: $record->ttl,
            content: $record->content,
        );
    }

    public function deleteRecord(string $zoneId, string $recordId): void
    {
        $inwxId = (int) $recordId;
        if ($inwxId <= 0) {
            throw new INWXAPIException('Ungültige INWX-Record-ID: ' . $recordId);
        }
        $this->client->deleteRecord($inwxId);
    }

    public function replaceRrset(Rrset $rrset): Rrset
    {
        $type = RecordType::tryFrom($rrset->type->presentation);
        if ($type === null) {
            throw new CapabilityException('INWX-Schreiben unbekannter RFC-3597-Typen wird nicht unterstützt.');
        }
        $existing = array_values(array_filter(
            $this->listRecords($rrset->zoneId),
            fn(Record $record): bool => $record->type === $type && strcasecmp(rtrim($record->name, '.'), rtrim($rrset->ownerName, '.')) === 0
        ));
        $byContent = [];
        foreach ($existing as $record) {
            $byContent[$record->content] = $record;
        }
        $created = [];
        try {
            foreach (array_values(array_unique($rrset->rdata)) as $content) {
                if (!isset($byContent[$content])) {
                    $created[] = $this->createRecord(new Record('', $rrset->zoneId, $rrset->ownerName, $type, $rrset->ttl, $content));
                } elseif ($byContent[$content]->ttl !== $rrset->ttl) {
                    $this->updateRecord(new Record($byContent[$content]->id, $rrset->zoneId, $rrset->ownerName, $type, $rrset->ttl, $content));
                }
            }
            foreach ($existing as $record) {
                if (!in_array($record->content, $rrset->rdata, true)) {
                    $this->deleteRecord($rrset->zoneId, $record->id);
                }
            }
        } catch (\Throwable $error) {
            foreach ($created as $record) {
                try {
                    $this->deleteRecord($rrset->zoneId, $record->id);
                } catch (\Throwable) {
                }
            }
            throw new INWXAPIException('INWX-RRset-Änderung konnte nicht vollständig angewendet werden.', previous: $error);
        }
        foreach ($this->listRrsets($rrset->zoneId) as $observed) {
            if ($observed->type->equals($rrset->type) && strcasecmp(rtrim($observed->ownerName, '.'), rtrim($rrset->ownerName, '.')) === 0) {
                return $observed;
            }
        }
        throw new INWXAPIException('INWX lieferte das geschriebene RRset nicht zurück.');
    }

    public function deleteRrset(string $zoneId, string $ownerName, string $type): void
    {
        foreach ($this->listRecords($zoneId) as $record) {
            if ($record->type->value === $type && strcasecmp(rtrim($record->name, '.'), rtrim($ownerName, '.')) === 0) {
                $this->deleteRecord($zoneId, $record->id);
            }
        }
    }

    // ── DNSSEC operations ─────────────────────────────────────────────────────

    public function getDnssecProfile(string $zoneId): DNSSECProfile
    {
        $keyInfo = $this->client->getDnsKeyInfo($zoneId);
        $keys    = (array) ($keyInfo['dnskey'] ?? $keyInfo['keys'] ?? []);
        $signed  = $keys !== [];
        $state   = $signed ? DNSSECState::SIGNED : DNSSECState::UNSIGNED;

        $metadata = ['key_count' => count($keys)];

        return new DNSSECProfile(
            zoneId: $zoneId,
            state: $state,
            features: [
                'auto_managed' => false,
                'ds_available' => $signed,
            ],
            metadata: $metadata,
        );
    }

    public function executeDnssecAction(string $zoneId, string $action, array $payload = []): DNSSECProfile
    {
        match ($action) {
            'enable'  => $this->client->activateDnssec($zoneId),
            'disable' => $this->client->deactivateDnssec($zoneId),
            default   => throw new CapabilityException(
                sprintf('INWX kennt die DNSSEC-Aktion "%s" nicht.', $action)
            ),
        };

        return $this->getDnssecProfile($zoneId);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $row
     */
    private function mapZone(array $row): Zone
    {
        $name = (string) ($row['domain'] ?? $row['name'] ?? '');
        return new Zone(
            id: $name,
            name: $name,
            providerId: self::ID,
            active: true,
            metadata: [
                'type' => (string) ($row['type'] ?? 'MASTER'),
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

        $id   = (string) ($row['id'] ?? '');
        $name = (string) ($row['name'] ?? '');
        // INWX returns "@" for the apex record
        if ($name === '@') {
            $name = '';
        }
        $content = (string) ($row['content'] ?? '');
        $ttl     = (int) ($row['ttl'] ?? 3600);

        return new Record(
            id: $id !== '' ? $id : self::contentHash($content),
            zoneId: $zoneId,
            name: $name,
            type: $type,
            ttl: $ttl,
            content: $content,
        );
    }
}

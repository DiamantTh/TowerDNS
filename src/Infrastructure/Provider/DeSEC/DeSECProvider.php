<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Provider\DeSEC;

use TowerDNS\Application\Contracts\Capability;
use TowerDNS\Application\Exception\CapabilityException;
use TowerDNS\Domain\DNS\DnssecProfile;
use TowerDNS\Domain\DNS\DnssecState;
use TowerDNS\Domain\DNS\Record;
use TowerDNS\Domain\DNS\RecordType;
use TowerDNS\Domain\DNS\Zone;
use TowerDNS\Infrastructure\Provider\AbstractDnsProvider;

/**
 * deSEC provider adapter.
 *
 * deSEC manages DNSSEC fully automatically: every zone is signed by the
 * provider and the user only consumes status and DS information. The
 * adapter therefore advertises {@see Capability::DNSSEC_AUTO_MANAGED} and
 * exposes status reads, but does not expose imperative DNSSEC actions.
 */
final class DeSECProvider extends AbstractDnsProvider
{
    public const ID = 'desec';

    public function __construct(private readonly DeSECApiClient $client)
    {
        parent::__construct();
    }

    public function id(): string
    {
        return self::ID;
    }

    public function displayName(): string
    {
        return 'deSEC';
    }

    protected function capabilityMap(): array
    {
        return [
            Capability::ZONE_LIST          => true,
            Capability::ZONE_READ          => true,
            Capability::ZONE_CREATE        => true,
            Capability::ZONE_DELETE        => true,
            Capability::ZONE_UPDATE        => false,

            Capability::RECORD_LIST        => true,
            Capability::RECORD_CREATE      => true,
            Capability::RECORD_UPDATE      => true,
            Capability::RECORD_DELETE      => true,
            Capability::RECORD_COMMENT     => false,

            Capability::DNSSEC_STATUS_READ    => true,
            Capability::DNSSEC_AUTO_MANAGED   => true,
            Capability::DNSSEC_DS_READ        => true,
            // deSEC does not expose imperative DNSSEC actions to clients.
            Capability::DNSSEC_ACTION_EXECUTE => false,
            Capability::DNSSEC_KEY_LIST       => false,
            Capability::DNSSEC_KEY_ROLLOVER   => false,

            Capability::PROVIDER_CREDENTIALS_MANAGE => true,
        ];
    }

    public function listZones(): array
    {
        $zones = [];
        foreach ($this->client->listDomains() as $row) {
            $zones[] = $this->mapZone($row);
        }
        return $zones;
    }

    public function createZone(string $zoneName): Zone
    {
        return $this->mapZone($this->client->createDomain($zoneName));
    }

    public function deleteZone(string $zoneId): void
    {
        $this->client->deleteDomain($zoneId);
    }

    public function listRecords(string $zoneId): array
    {
        $records = [];
        foreach ($this->client->getRRSets($zoneId) as $rrset) {
            $type = $this->mapType($rrset['type'] ?? '');
            if ($type === null) {
                continue;
            }
            $name = (string) ($rrset['subname'] ?? '');
            $ttl  = (int) ($rrset['ttl'] ?? 3600);

            /** @var list<string> $contents */
            $contents = $rrset['records'] ?? [];
            foreach ($contents as $content) {
                $records[] = new Record(
                    id:      $this->buildRecordId($zoneId, $name, $type, $content),
                    zoneId:  $zoneId,
                    name:    $name,
                    type:    $type,
                    ttl:     $ttl,
                    content: $content,
                );
            }
        }
        return $records;
    }

    public function createRecord(Record $record): Record
    {
        try {
            $this->client->createRRSet(
                $record->zoneId,
                $record->name,
                $record->type->value,
                [$record->content],
                $record->ttl,
            );
        } catch (DeSECApiException $e) {
            if ($e->getCode() !== 422) {
                throw $e;
            }
            // RRset already exists — add the new content entry to it.
            $existing = $this->client->getRRSet($record->zoneId, $record->name, $record->type->value);
            /** @var list<string> $current */
            $current = $existing['records'] ?? [];
            if (!in_array($record->content, $current, true)) {
                $current[] = $record->content;
            }
            $this->client->modifyRRSet(
                $record->zoneId,
                $record->name,
                $record->type->value,
                $current,
                $record->ttl,
            );
        }

        return new Record(
            id:       $this->buildRecordId($record->zoneId, $record->name, $record->type, $record->content),
            zoneId:   $record->zoneId,
            name:     $record->name,
            type:     $record->type,
            ttl:      $record->ttl,
            content:  $record->content,
            comment:  $record->comment,
            metadata: $record->metadata,
        );
    }

    public function updateRecord(Record $record): Record
    {
        [$subname, $typeStr, $oldHash] = $this->parseRecordId($record->zoneId, $record->id);

        $existing = $this->client->getRRSet($record->zoneId, $subname, $typeStr);
        /** @var list<string> $current */
        $current = $existing['records'] ?? [];
        $ttl     = (int) ($existing['ttl'] ?? $record->ttl);

        // Replace only the entry whose content hash matches the old record.
        $updated = array_map(
            fn(string $c) => self::contentHash($c) === $oldHash ? $record->content : $c,
            $current,
        );
        $updated = array_values(array_unique($updated));

        $this->client->modifyRRSet(
            $record->zoneId,
            $subname,
            $typeStr,
            $updated,
            $record->ttl !== $ttl ? $record->ttl : $ttl,
        );

        return new Record(
            id:       $this->buildRecordId($record->zoneId, $record->name, $record->type, $record->content),
            zoneId:   $record->zoneId,
            name:     $record->name,
            type:     $record->type,
            ttl:      $record->ttl,
            content:  $record->content,
            comment:  $record->comment,
            metadata: $record->metadata,
        );
    }

    public function deleteRecord(string $zoneId, string $recordId): void
    {
        [$subname, $typeStr, $oldHash] = $this->parseRecordId($zoneId, $recordId);

        $existing = $this->client->getRRSet($zoneId, $subname, $typeStr);
        /** @var list<string> $current */
        $current = $existing['records'] ?? [];
        $ttl     = (int) ($existing['ttl'] ?? 3600);

        $remaining = array_values(array_filter(
            $current,
            fn(string $c) => self::contentHash($c) !== $oldHash,
        ));

        if ($remaining === []) {
            // Last entry — remove the entire RRset.
            $this->client->deleteRRSet($zoneId, $subname, $typeStr);
        } else {
            $this->client->modifyRRSet($zoneId, $subname, $typeStr, $remaining, $ttl);
        }
    }

    public function getDnssecProfile(string $zoneId): DnssecProfile
    {
        $row = $this->client->getDomain($zoneId);

        $features = [
            'auto_managed' => true,
            'ds_available' => isset($row['keys']) && is_array($row['keys']) && $row['keys'] !== [],
        ];

        $metadata = [];
        if (isset($row['published']) && is_string($row['published'])) {
            $metadata['published'] = $row['published'];
        }
        if (isset($row['minimum_ttl'])) {
            $metadata['minimum_ttl'] = (int) $row['minimum_ttl'];
        }

        return new DnssecProfile(
            zoneId: $zoneId,
            state: DnssecState::SIGNED,
            features: $features,
            metadata: $metadata,
        );
    }

    public function executeDnssecAction(string $zoneId, string $action, array $payload = []): DnssecProfile
    {
        throw new CapabilityException('deSEC verwaltet DNSSEC vollautomatisch; manuelle Aktionen sind nicht moeglich.');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapZone(array $row): Zone
    {
        $name = (string) ($row['name'] ?? '');
        $metadata = [];
        if (isset($row['created'])) {
            $metadata['created'] = (string) $row['created'];
        }
        if (isset($row['published'])) {
            $metadata['published'] = (string) $row['published'];
        }
        if (isset($row['minimum_ttl'])) {
            $metadata['minimum_ttl'] = (int) $row['minimum_ttl'];
        }

        return new Zone(
            id: $name,
            name: $name,
            providerId: $this->id(),
            active: true,
            metadata: $metadata,
        );
    }

    private function mapType(string $type): ?RecordType
    {
        return RecordType::tryFrom(strtoupper($type));
    }

    /**
     * Build a 4-part record ID: zone|subname|type|contenthash.
     */
    private function buildRecordId(string $zoneId, string $subname, RecordType $type, string $content): string
    {
        $sub = $subname === '' ? '@' : $subname;
        return sprintf('%s|%s|%s|%s', $zoneId, $sub, $type->value, self::contentHash($content));
    }

    /**
     * Parse a 4-part record ID back into its components.
     *
     * @return array{0: string, 1: string, 2: string} [subname, type, contentHash]
     */
    private function parseRecordId(string $zoneId, string $recordId): array
    {
        $parts = explode('|', $recordId);
        if (count($parts) !== 4 || $parts[0] !== $zoneId) {
            throw new \InvalidArgumentException(
                sprintf('Ungueltige deSEC-Record-ID: "%s"', $recordId)
            );
        }
        return [$parts[1] === '@' ? '' : $parts[1], $parts[2], $parts[3]];
    }
}

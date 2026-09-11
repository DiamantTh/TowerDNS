<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Provider;

use TowerDNS\Application\Contracts\DnsProviderInterface;
use TowerDNS\Application\Contracts\ProviderCapabilitySet;
use TowerDNS\Application\Contracts\RrsetProviderInterface;
use TowerDNS\Domain\DNS\DnsRecordType;
use TowerDNS\Domain\DNS\Rrset;

/**
 * Convenience base for provider adapters.
 *
 * Concrete adapters declare a capability map; this base class wraps it in an
 * immutable {@see ProviderCapabilitySet}.
 */
abstract class AbstractDnsProvider implements DnsProviderInterface, RrsetProviderInterface
{
    private readonly ProviderCapabilitySet $capabilitySet;

    public function __construct()
    {
        $this->capabilitySet = new ProviderCapabilitySet($this->capabilityMap());
    }

    /**
     * @return array<string, bool>
     */
    abstract protected function capabilityMap(): array;

    public function capabilities(): ProviderCapabilitySet
    {
        return $this->capabilitySet;
    }

    /** @return list<Rrset> */
    public function listRrsets(string $zoneId): array
    {
        /** @var array<string, array{owner: string, type: DnsRecordType, ttl: int, rdata: list<string>, ids: list<string>}> $sets */
        $sets = [];
        foreach ($this->listRecords($zoneId) as $record) {
            $type = DnsRecordType::parse($record->type->value);
            $key = strtolower(rtrim($record->name, '.')) . "\0" . $type->code;
            if (!isset($sets[$key])) {
                $sets[$key] = ['owner' => $record->name, 'type' => $type, 'ttl' => $record->ttl, 'rdata' => [], 'ids' => []];
            }
            $sets[$key]['rdata'][] = $record->content;
            $sets[$key]['ids'][] = $record->id;
        }

        $result = [];
        foreach ($sets as $set) {
            if ($set['rdata'] === []) {
                continue;
            }
            $result[] = new Rrset(
                $zoneId, $set['owner'], $set['type'], $set['ttl'], $set['rdata'], implode(',', $set['ids']),
            );
        }
        return $result;
    }

    public function replaceRrset(Rrset $rrset): Rrset
    {
        throw new \LogicException(sprintf(
            'Provider %s muss replaceRrset() atomar oder mit eigener Konfliktbehandlung implementieren.',
            $this->id(),
        ));
    }

    public function deleteRrset(string $zoneId, string $ownerName, string $type): void
    {
        throw new \LogicException(sprintf(
            'Provider %s muss deleteRrset() mit eigener Konfliktbehandlung implementieren.',
            $this->id(),
        ));
    }

    /**
     * Stable, 12-character hex fingerprint of a record content string.
     *
     * Used to build per-record IDs that are unique within an RRset while
     * remaining deterministic across list → update/delete round-trips.
     * 12 hex chars = 48 bits of entropy — sufficient to distinguish records
     * inside a single RRset (typically ≤ 20 entries).
     */
    protected static function contentHash(string $content): string
    {
        return substr(hash('sha256', $content), 0, 12);
    }
}

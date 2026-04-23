<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Provider\Cloudflare;

use TowerDNS\Application\Contracts\Capability;
use TowerDNS\Application\Exception\NotImplementedException;
use TowerDNS\Domain\DNS\DnssecProfile;
use TowerDNS\Domain\DNS\Record;
use TowerDNS\Domain\DNS\Zone;
use TowerDNS\Infrastructure\Provider\AbstractDnsProvider;

/**
 * Cloudflare provider scaffolding.
 *
 * The capability set already reflects what Cloudflare can deliver
 * (auto-managed DNSSEC, comments via tags, no rollover via API), but the
 * concrete API binding is intentionally left as TODO and clearly signalled
 * via {@see NotImplementedException}.
 */
final class CloudflareProvider extends AbstractDnsProvider
{
    public const ID = 'cloudflare';

    public function __construct(
        /** @phpstan-ignore property.onlyWritten */
        private readonly string $apiToken,
    ) {
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

    public function listZones(): array
    {
        throw NotImplementedException::forFeature(self::ID, 'listZones');
    }

    public function createZone(string $zoneName): Zone
    {
        throw NotImplementedException::forFeature(self::ID, 'createZone');
    }

    public function deleteZone(string $zoneId): void
    {
        throw NotImplementedException::forFeature(self::ID, 'deleteZone');
    }

    public function listRecords(string $zoneId): array
    {
        throw NotImplementedException::forFeature(self::ID, 'listRecords');
    }

    public function createRecord(Record $record): Record
    {
        throw NotImplementedException::forFeature(self::ID, 'createRecord');
    }

    public function updateRecord(Record $record): Record
    {
        throw NotImplementedException::forFeature(self::ID, 'updateRecord');
    }

    public function deleteRecord(string $zoneId, string $recordId): void
    {
        throw NotImplementedException::forFeature(self::ID, 'deleteRecord');
    }

    public function getDnssecProfile(string $zoneId): DnssecProfile
    {
        throw NotImplementedException::forFeature(self::ID, 'getDnssecProfile');
    }

    public function executeDnssecAction(string $zoneId, string $action, array $payload = []): DnssecProfile
    {
        throw NotImplementedException::forFeature(self::ID, 'executeDnssecAction');
    }
}

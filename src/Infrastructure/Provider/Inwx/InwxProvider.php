<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Provider\Inwx;

use TowerDNS\Application\Contracts\Capability;
use TowerDNS\Application\Exception\NotImplementedException;
use TowerDNS\Domain\DNS\DnssecProfile;
use TowerDNS\Domain\DNS\Record;
use TowerDNS\Domain\DNS\Zone;
use TowerDNS\Infrastructure\Provider\AbstractDnsProvider;

/**
 * INWX provider scaffolding.
 *
 * INWX exposes a JSON-RPC / XML-RPC API for nameserver and DNSSEC management,
 * including direct DS record submission for registered domains. The
 * capability set reflects this: imperative DNSSEC actions are supported,
 * but key listing/rollover are tied to registry workflows.
 */
final class InwxProvider extends AbstractDnsProvider
{
    public const ID = 'inwx';

    public function __construct(
        private readonly string $username,
        private readonly string $password,
    ) {
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
            Capability::ZONE_UPDATE => true,

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

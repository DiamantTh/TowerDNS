<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Module\GoogleCloudDNS;

use Google\Service\Dns;
use Google\Service\Dns\Change;
use Google\Service\Dns\ManagedZone;
use Google\Service\Dns\ManagedZonesListResponse;
use Google\Service\Dns\ResourceRecordSet;
use Google\Service\Dns\ResourceRecordSetsListResponse;
use Google\Service\Exception as GoogleException;
use GuzzleHttp\Exception\GuzzleException;
use TowerDNS\Application\Contracts\Capability;
use TowerDNS\Application\Exception\CapabilityException;
use TowerDNS\Application\Exception\ProviderRequestException;
use TowerDNS\Domain\DNS\DNSRecordType;
use TowerDNS\Domain\DNS\DNSSECProfile;
use TowerDNS\Domain\DNS\DNSSECState;
use TowerDNS\Domain\DNS\Record;
use TowerDNS\Domain\DNS\RecordType;
use TowerDNS\Domain\DNS\Rrset;
use TowerDNS\Domain\DNS\Zone;
use TowerDNS\Infrastructure\Provider\AbstractDNSProvider;

/** Google Cloud DNS managed zones. This adapter does not access Cloud Domains.
 * @psalm-api Loaded through the dynamic provider-module contract.
 */
final class GoogleCloudDNSProvider extends AbstractDNSProvider
{
    public const string ID = 'google-cloud-dns';

    public function __construct(private readonly Dns $client, private readonly string $projectId)
    {
        if (trim($projectId) === '') {
            throw new \InvalidArgumentException('Google Cloud DNS requires a project ID.');
        }
        parent::__construct();
    }

    #[\Override]
    public function id(): string
    {
        return self::ID;
    }

    #[\Override]
    public function displayName(): string
    {
        return 'Google Cloud DNS';
    }

    #[\Override]
    protected function capabilityMap(): array
    {
        return [
            Capability::ZONE_LIST                   => true,
            Capability::ZONE_READ                   => true,
            Capability::ZONE_CREATE                 => true,
            Capability::ZONE_DELETE                 => true,
            Capability::RECORD_LIST                 => true,
            Capability::RECORD_CREATE               => true,
            Capability::RECORD_UPDATE               => true,
            Capability::RECORD_DELETE               => true,
            Capability::DNSSEC_STATUS_READ          => true,
            Capability::PROVIDER_CREDENTIALS_MANAGE => true,
        ];
    }

    #[\Override]
    public function listZones(): array
    {
        $zones   = [];
        $options = [];
        do {
            $page = $this->request(fn(): ManagedZonesListResponse => $this->client->managedZones->listManagedZones($this->projectId, $options));
            foreach ($this->managedZoneItems($page->getManagedZones()) as $zone) {
                $zones[] = $this->mapZone($zone);
            }
            $options = ['pageToken' => $this->pageToken($page->getNextPageToken())];
        } while ($options['pageToken'] !== null && $options['pageToken'] !== '');
        return $zones;
    }

    #[\Override]
    public function createZone(string $zoneName): Zone
    {
        $name = strtolower(rtrim($zoneName, '.'));
        $zone = new ManagedZone([
            'name'        => 'towerdns-' . substr(hash('sha256', $name), 0, 32),
            'dnsName'     => $name . '.',
            'description' => 'Managed by TowerDNS',
            'visibility'  => 'public',
        ]);
        return $this->mapZone($this->request(fn(): ManagedZone => $this->client->managedZones->create($this->projectId, $zone)));
    }

    #[\Override]
    public function deleteZone(string $zoneId): void
    {
        // Google rejects non-empty zones; never implicitly purge their records.
        $this->request(fn(): mixed => $this->client->managedZones->delete($this->projectId, $zoneId));
    }

    #[\Override]
    public function listRecords(string $zoneId): array
    {
        $zone    = $this->getZone($zoneId);
        $records = [];
        $options = [];
        do {
            $page = $this->request(fn(): ResourceRecordSetsListResponse => $this->client->resourceRecordSets->listResourceRecordSets($this->projectId, $zoneId, $options));
            foreach ($this->rrsetItems($page->getRrsets()) as $rrset) {
                $type = RecordType::tryFrom($this->stringValue($rrset->getType()));
                // Routing policy records cannot be represented as individual static RDATA.
                if ($type === null || $this->hasRoutingPolicy($rrset->getRoutingPolicy())) {
                    continue;
                }
                foreach ($this->rdataItems($rrset->getRrdatas()) as $content) {
                    $records[] = $this->mapRecord($zoneId, $zone, $rrset, $type, $content);
                }
            }
            $options = ['pageToken' => $this->pageToken($page->getNextPageToken())];
        } while ($options['pageToken'] !== null && $options['pageToken'] !== '');
        return $records;
    }

    #[\Override]
    public function createRecord(Record $record): Record
    {
        $zone       = $this->getZone($record->zoneId);
        $name       = $this->absoluteName($record->name, $zone);
        $existing   = $this->findRrset($record->zoneId, $name, $record->type->value);
        $contents   = $existing?->getRrdatas() ?? [];
        $contents[] = $record->content;
        $new        = $this->rrset($name, $record->type->value, $record->ttl, array_values($contents));
        $this->change($record->zoneId, [$new], $existing === null ? [] : [$existing]);
        return $this->mapRecord($record->zoneId, $zone, $new, $record->type, $record->content);
    }

    #[\Override]
    public function updateRecord(Record $record): Record
    {
        [$oldName, $oldType, $hash] = $this->parseId($record->zoneId, $record->id);
        $zone                       = $this->getZone($record->zoneId);
        $name                       = $this->absoluteName($record->name, $zone);
        $old                        = $this->findRrset($record->zoneId, $oldName, $oldType);
        if ($old === null) {
            throw new ProviderRequestException('Google Cloud DNS record no longer exists; refresh the zone before retrying.', 409);
        }
        $remaining = $this->remaining($old, $hash);
        $deletions = [$old];
        $additions = [];
        if ($oldName === $name && $oldType === $record->type->value) {
            $contents = $remaining;
        } else {
            if ($remaining !== []) {
                $additions[] = $this->rrset($oldName, $oldType, $this->integerValue($old->getTtl()), $remaining);
            }
            $target   = $this->findRrset($record->zoneId, $name, $record->type->value);
            $contents = $this->rdataItems($target?->getRrdatas());
            if ($target !== null) {
                $deletions[] = $target;
            }
        }
        $contents[]  = $record->content;
        $new         = $this->rrset($name, $record->type->value, $record->ttl, $contents);
        $additions[] = $new;
        $this->change($record->zoneId, $additions, $deletions);
        return $this->mapRecord($record->zoneId, $zone, $new, $record->type, $record->content);
    }

    #[\Override]
    public function deleteRecord(string $zoneId, string $recordId): void
    {
        [$name, $type, $hash] = $this->parseId($zoneId, $recordId);
        $old                  = $this->findRrset($zoneId, $name, $type);
        if ($old === null) {
            throw new ProviderRequestException('Google Cloud DNS record no longer exists; refresh the zone before retrying.', 409);
        }
        $remaining = $this->remaining($old, $hash);
        $additions = $remaining === [] ? [] : [$this->rrset($name, $type, $this->integerValue($old->getTtl()), $remaining)];
        $this->change($zoneId, $additions, [$old]);
    }

    /** @return list<Rrset> */
    #[\Override]
    public function listRrsets(string $zoneId): array
    {
        $zone    = $this->getZone($zoneId);
        $sets    = [];
        $options = [];
        do {
            $page = $this->request(fn(): ResourceRecordSetsListResponse => $this->client->resourceRecordSets->listResourceRecordSets($this->projectId, $zoneId, $options));
            foreach ($this->rrsetItems($page->getRrsets()) as $native) {
                $nativeRdata = $this->rdataItems($native->getRrdatas());
                if ($this->hasRoutingPolicy($native->getRoutingPolicy()) || $nativeRdata === []) {
                    continue;
                }
                try {
                    $type = DNSRecordType::parse($this->stringValue($native->getType()));
                } catch (\InvalidArgumentException) {
                    continue;
                }
                $sets[] = new Rrset($zoneId, $this->relativeName($this->stringValue($native->getName()), $zone), $type, $this->integerValue($native->getTtl()), $nativeRdata);
            }
            $options = ['pageToken' => $this->pageToken($page->getNextPageToken())];
        } while ($options['pageToken'] !== null && $options['pageToken'] !== '');
        return $sets;
    }

    #[\Override]
    public function replaceRrset(Rrset $rrset): Rrset
    {
        $zone = $this->getZone($rrset->zoneId);
        $name = $this->absoluteName($rrset->ownerName, $zone);
        $old  = $this->findRrset($rrset->zoneId, $name, $rrset->type->presentation);
        $new  = $this->rrset($name, $rrset->type->presentation, $rrset->ttl, array_values(array_unique($rrset->rdata)));
        $this->change($rrset->zoneId, [$new], $old === null ? [] : [$old]);
        foreach ($this->listRrsets($rrset->zoneId) as $observed) {
            if ($observed->type->equals($rrset->type) && strcasecmp(rtrim($observed->ownerName, '.'), rtrim($rrset->ownerName, '.')) === 0) {
                return $observed;
            }
        }
        throw new ProviderRequestException('Google Cloud DNS lieferte das geschriebene RRset nicht zurück.');
    }

    #[\Override]
    public function deleteRrset(string $zoneId, string $ownerName, string $type): void
    {
        $zone = $this->getZone($zoneId);
        $old  = $this->findRrset($zoneId, $this->absoluteName($ownerName, $zone), $type);
        if ($old !== null) {
            $this->change($zoneId, [], [$old]);
        }
    }

    #[\Override]
    public function getDnssecProfile(string $zoneId): DNSSECProfile
    {
        $state = $this->dnssecState($this->getZone($zoneId)->getDnssecConfig());
        return new DNSSECProfile($zoneId, $this->normalizeDnssecState($state), [
            'auto_managed' => $state === 'on',
        ], ['provider_state' => $state]);
    }

    private function normalizeDnssecState(string $state): DNSSECState
    {
        return match ($state) {
            'on'           => DNSSECState::SIGNED,
            'off'          => DNSSECState::UNSIGNED,
            'transfer'     => DNSSECState::PARTIAL,
            default        => DNSSECState::UNKNOWN,
        };
    }

    #[\Override]
    public function executeDnssecAction(string $zoneId, string $action, array $payload = []): DNSSECProfile
    {
        throw new CapabilityException('Google Cloud DNS DNSSEC actions are not supported by this adapter.');
    }

    private function getZone(string $zoneId): ManagedZone
    {
        return $this->request(fn(): ManagedZone => $this->client->managedZones->get($this->projectId, $zoneId));
    }

    private function findRrset(string $zoneId, string $name, string $type): ?ResourceRecordSet
    {
        try {
            $rrset = $this->request(fn(): ResourceRecordSet => $this->client->resourceRecordSets->get($this->projectId, $zoneId, $name, $type));
        } catch (ProviderRequestException $e) {
            if ($e->getCode() === 404) {
                return null;
            }
            throw $e;
        }
        if ($this->hasRoutingPolicy($rrset->getRoutingPolicy())) {
            throw new CapabilityException('Google Cloud DNS routing policy records cannot be edited as static records.');
        }
        return $rrset;
    }

    /** @param list<string> $contents */
    private function rrset(string $name, string $type, int $ttl, array $contents): ResourceRecordSet
    {
        return new ResourceRecordSet(['name' => $name, 'type' => $type, 'ttl' => $ttl, 'rrdatas' => array_values(array_unique($contents))]);
    }

    /**
     * @param list<ResourceRecordSet> $additions
     * @param list<ResourceRecordSet> $deletions
     */
    private function change(string $zoneId, array $additions, array $deletions): void
    {
        // Exact original deletions provide optimistic concurrency protection:
        // Google rejects the entire atomic change if another writer changed an RRset.
        $change = new Change(['additions' => $additions, 'deletions' => $deletions]);
        $this->request(fn(): Change => $this->client->changes->create($this->projectId, $zoneId, $change));
    }

    /** @return list<string> */
    private function remaining(?ResourceRecordSet $rrset, string $hash): array
    {
        $contents  = $this->rdataItems($rrset?->getRrdatas());
        $remaining = array_values(array_filter($contents, static fn(string $content): bool => hash('sha256', $content) !== $hash));
        if (count($contents) === count($remaining)) {
            throw new ProviderRequestException('Google Cloud DNS record no longer exists; refresh the zone before retrying.', 409);
        }
        return $remaining;
    }

    private function mapZone(ManagedZone $zone): Zone
    {
        $nameservers = $this->rdataItems($zone->getNameServers());

        return new Zone($this->requiredString($zone->getName(), 'managed zone name'), rtrim($this->requiredString($zone->getDnsName(), 'managed zone DNS name'), '.'), self::ID, true, metadata: [
            'project_id'  => $this->projectId,
            'visibility'  => $zone->getVisibility(),
            'nameservers' => implode(', ', $nameservers),
        ]);
    }

    /** @return list<ManagedZone> */
    private function managedZoneItems(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }

        return array_values(array_filter($items, static fn(mixed $item): bool => $item instanceof ManagedZone));
    }

    /** @return list<ResourceRecordSet> */
    private function rrsetItems(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }

        return array_values(array_filter($items, static fn(mixed $item): bool => $item instanceof ResourceRecordSet));
    }

    /** @return list<string> */
    private function rdataItems(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }

        return array_values(array_filter($items, static fn(mixed $item): bool => is_string($item)));
    }

    private function pageToken(mixed $token): ?string
    {
        return is_string($token) && $token !== '' ? $token : null;
    }

    private function hasRoutingPolicy(mixed $routingPolicy): bool
    {
        return $routingPolicy !== null;
    }

    private function dnssecState(mixed $configuration): string
    {
        if (!$configuration instanceof \Google\Service\Dns\ManagedZoneDnsSecConfig) {
            return 'unknown';
        }

        return $this->stringValue($configuration->getState());
    }

    private function absoluteName(string $name, ManagedZone $zone): string
    {
        $apex       = strtolower(rtrim($this->requiredString($zone->getDnsName(), 'managed zone DNS name'), '.'));
        $normalized = strtolower(rtrim($name, '.'));
        if ($name === '' || $name === '@' || $normalized === $apex) {
            return $apex . '.';
        }
        if (str_ends_with($normalized, '.' . $apex)) {
            return $normalized . '.';
        }
        if (str_ends_with($name, '.')) {
            throw new \InvalidArgumentException('Record name must belong to the managed zone.');
        }
        return $normalized . '.' . $apex . '.';
    }

    private function relativeName(string $name, ManagedZone $zone): string
    {
        $name = rtrim($name, '.');
        $apex = rtrim($this->requiredString($zone->getDnsName(), 'managed zone DNS name'), '.');
        return strcasecmp($name, $apex) === 0 ? '' : substr($name, 0, -strlen($apex) - 1);
    }

    private function mapRecord(string $zoneId, ManagedZone $zone, ResourceRecordSet $rrset, RecordType $type, string $content): Record
    {
        $name     = $this->requiredString($rrset->getName(), 'record owner name');
        $apex     = $this->requiredString($zone->getDnsName(), 'managed zone DNS name');
        $relative = strcasecmp($name, $apex) === 0 ? '' : substr($name, 0, -strlen($apex) - 1);
        $id       = base64_encode(json_encode([$zoneId, $name, $type->value, hash('sha256', $content)], JSON_THROW_ON_ERROR));
        return new Record($id, $zoneId, $relative, $type, $this->integerValue($rrset->getTtl()), $content);
    }

    private function requiredString(mixed $value, string $field): string
    {
        if (!is_string($value) || $value === '') {
            throw new ProviderRequestException('Google Cloud DNS returned an invalid ' . $field . '.');
        }

        return $value;
    }

    private function stringValue(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function integerValue(mixed $value): int
    {
        if (!is_int($value) || $value < 0) {
            throw new ProviderRequestException('Google Cloud DNS returned an invalid TTL.');
        }

        return $value;
    }

    /** @return array{string, string, string} */
    private function parseId(string $zoneId, string $id): array
    {
        $decoded = base64_decode($id, true);
        $parts   = $decoded === false ? null : json_decode($decoded, true);
        if (!is_array($parts) || !array_is_list($parts) || count($parts) !== 4 || $parts[0] !== $zoneId
                              || !is_string($parts[1]) || !is_string($parts[2]) || !is_string($parts[3])
                              || RecordType::tryFrom($parts[2]) === null || !preg_match('/^[a-f0-9]{64}$/D', $parts[3])) {
            throw new \InvalidArgumentException('Invalid Google Cloud DNS record ID.');
        }
        return [$parts[1], $parts[2], $parts[3]];
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function request(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (GoogleException | GuzzleException $e) {
            // SDK exception text may include request headers or credential material.
            throw new ProviderRequestException('Google Cloud DNS request failed (HTTP ' . $e->getCode() . ').', $e->getCode());
        }
    }
}

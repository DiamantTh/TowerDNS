<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Module\GoogleCloudDNS;

use Google\Service\Dns;
use Google\Service\Dns\Change;
use Google\Service\Dns\ManagedZone;
use Google\Service\Dns\ResourceRecordSet;
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

/** Google Cloud DNS managed zones. This adapter does not access Cloud Domains. */
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

    public function id(): string
    {
        return self::ID;
    }

    public function displayName(): string
    {
        return 'Google Cloud DNS';
    }

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

    public function listZones(): array
    {
        $zones   = [];
        $options = [];
        do {
            $page = $this->request(fn() => $this->client->managedZones->listManagedZones($this->projectId, $options));
            foreach ($page->getManagedZones() ?? [] as $zone) {
                $zones[] = $this->mapZone($zone);
            }
            $options = ['pageToken' => $page->getNextPageToken()];
        } while ($options['pageToken'] !== null && $options['pageToken'] !== '');
        return $zones;
    }

    public function createZone(string $zoneName): Zone
    {
        $name = strtolower(rtrim($zoneName, '.'));
        $zone = new ManagedZone([
            'name'        => 'towerdns-' . substr(hash('sha256', $name), 0, 32),
            'dnsName'     => $name . '.',
            'description' => 'Managed by TowerDNS',
            'visibility'  => 'public',
        ]);
        return $this->mapZone($this->request(fn() => $this->client->managedZones->create($this->projectId, $zone)));
    }

    public function deleteZone(string $zoneId): void
    {
        // Google rejects non-empty zones; never implicitly purge their records.
        $this->request(fn() => $this->client->managedZones->delete($this->projectId, $zoneId));
    }

    public function listRecords(string $zoneId): array
    {
        $zone    = $this->getZone($zoneId);
        $records = [];
        $options = [];
        do {
            $page = $this->request(fn() => $this->client->resourceRecordSets->listResourceRecordSets($this->projectId, $zoneId, $options));
            foreach ($page->getRrsets() ?? [] as $rrset) {
                $type = RecordType::tryFrom((string) $rrset->getType());
                // Routing policy records cannot be represented as individual static RDATA.
                if ($type === null || $rrset->getRoutingPolicy() !== null) {
                    continue;
                }
                foreach ($rrset->getRrdatas() ?? [] as $content) {
                    $records[] = $this->mapRecord($zoneId, $zone, $rrset, $type, $content);
                }
            }
            $options = ['pageToken' => $page->getNextPageToken()];
        } while ($options['pageToken'] !== null && $options['pageToken'] !== '');
        return $records;
    }

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
                $additions[] = $this->rrset($oldName, $oldType, (int) $old->getTtl(), $remaining);
            }
            $target   = $this->findRrset($record->zoneId, $name, $record->type->value);
            $contents = $target?->getRrdatas() ?? [];
            if ($target !== null) {
                $deletions[] = $target;
            }
        }
        $contents[]  = $record->content;
        $new         = $this->rrset($name, $record->type->value, $record->ttl, array_values($contents));
        $additions[] = $new;
        $this->change($record->zoneId, $additions, $deletions);
        return $this->mapRecord($record->zoneId, $zone, $new, $record->type, $record->content);
    }

    public function deleteRecord(string $zoneId, string $recordId): void
    {
        [$name, $type, $hash] = $this->parseId($zoneId, $recordId);
        $old                  = $this->findRrset($zoneId, $name, $type);
        if ($old === null) {
            throw new ProviderRequestException('Google Cloud DNS record no longer exists; refresh the zone before retrying.', 409);
        }
        $remaining = $this->remaining($old, $hash);
        $additions = $remaining === [] ? [] : [$this->rrset($name, $type, (int) $old->getTtl(), $remaining)];
        $this->change($zoneId, $additions, [$old]);
    }

    /** @return list<Rrset> */
    public function listRrsets(string $zoneId): array
    {
        $zone    = $this->getZone($zoneId);
        $sets    = [];
        $options = [];
        do {
            $page = $this->request(fn() => $this->client->resourceRecordSets->listResourceRecordSets($this->projectId, $zoneId, $options));
            foreach ($page->getRrsets() ?? [] as $native) {
                if ($native->getRoutingPolicy() !== null || ($native->getRrdatas() ?? []) === []) {
                    continue;
                }
                try {
                    $type = DNSRecordType::parse((string) $native->getType());
                } catch (\InvalidArgumentException) {
                    continue;
                }
                $rdata = array_values($native->getRrdatas() ?? []);
                if ($rdata === []) {
                    continue;
                }
                $sets[] = new Rrset($zoneId, $this->relativeName((string) $native->getName(), $zone), $type, (int) $native->getTtl(), $rdata);
            }
            $options = ['pageToken' => $page->getNextPageToken()];
        } while ($options['pageToken'] !== null && $options['pageToken'] !== '');
        return $sets;
    }

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

    public function deleteRrset(string $zoneId, string $ownerName, string $type): void
    {
        $zone = $this->getZone($zoneId);
        $old  = $this->findRrset($zoneId, $this->absoluteName($ownerName, $zone), $type);
        if ($old !== null) {
            $this->change($zoneId, [], [$old]);
        }
    }

    public function getDnssecProfile(string $zoneId): DNSSECProfile
    {
        $state = $this->getZone($zoneId)->getDnssecConfig()?->getState();
        return new DNSSECProfile($zoneId, match ($state) {
            'on'           => DNSSECState::SIGNED,
            'off'          => DNSSECState::UNSIGNED,
            'transfer'     => DNSSECState::PARTIAL,
            default        => DNSSECState::UNKNOWN,
        }, ['auto_managed' => $state === 'on'], ['provider_state' => $state]);
    }

    public function executeDnssecAction(string $zoneId, string $action, array $payload = []): DNSSECProfile
    {
        throw new CapabilityException('Google Cloud DNS DNSSEC actions are not supported by this adapter.');
    }

    private function getZone(string $zoneId): ManagedZone
    {
        return $this->request(fn() => $this->client->managedZones->get($this->projectId, $zoneId));
    }

    private function findRrset(string $zoneId, string $name, string $type): ?ResourceRecordSet
    {
        try {
            $rrset = $this->request(fn() => $this->client->resourceRecordSets->get($this->projectId, $zoneId, $name, $type));
        } catch (ProviderRequestException $e) {
            if ($e->getCode() === 404) {
                return null;
            }
            throw $e;
        }
        if ($rrset->getRoutingPolicy() !== null) {
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
        $this->request(fn() => $this->client->changes->create($this->projectId, $zoneId, $change));
    }

    /** @return list<string> */
    private function remaining(?ResourceRecordSet $rrset, string $hash): array
    {
        $contents  = $rrset?->getRrdatas() ?? [];
        $remaining = array_values(array_filter($contents, static fn(string $content): bool => hash('sha256', $content) !== $hash));
        if (count($contents) === count($remaining)) {
            throw new ProviderRequestException('Google Cloud DNS record no longer exists; refresh the zone before retrying.', 409);
        }
        return $remaining;
    }

    private function mapZone(ManagedZone $zone): Zone
    {
        return new Zone((string) $zone->getName(), rtrim((string) $zone->getDnsName(), '.'), self::ID, true, metadata: [
            'project_id'  => $this->projectId,
            'visibility'  => $zone->getVisibility(),
            'nameservers' => implode(', ', $zone->getNameServers() ?? []),
        ]);
    }

    private function absoluteName(string $name, ManagedZone $zone): string
    {
        $apex       = strtolower(rtrim((string) $zone->getDnsName(), '.'));
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
        $apex = rtrim((string) $zone->getDnsName(), '.');
        return strcasecmp($name, $apex) === 0 ? '' : substr($name, 0, -strlen($apex) - 1);
    }

    private function mapRecord(string $zoneId, ManagedZone $zone, ResourceRecordSet $rrset, RecordType $type, string $content): Record
    {
        $name     = (string) $rrset->getName();
        $apex     = (string) $zone->getDnsName();
        $relative = strcasecmp($name, $apex) === 0 ? '' : substr($name, 0, -strlen($apex) - 1);
        $id       = base64_encode(json_encode([$zoneId, $name, $type->value, hash('sha256', $content)], JSON_THROW_ON_ERROR));
        return new Record($id, $zoneId, $relative, $type, (int) $rrset->getTtl(), $content);
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
    private function request(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (GoogleException | GuzzleException $e) {
            // SDK exception text may include request headers or credential material.
            throw new ProviderRequestException('Google Cloud DNS request failed (HTTP ' . $e->getCode() . ').', (int) $e->getCode());
        }
    }
}

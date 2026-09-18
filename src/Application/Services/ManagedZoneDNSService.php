<?php

declare(strict_types=1);

namespace TowerDNS\Application\Services;

use TowerDNS\Application\Contracts\AccountProviderFactoryInterface;
use TowerDNS\Application\Contracts\Capability;
use TowerDNS\Application\Contracts\DNSProviderInterface;
use TowerDNS\Application\Contracts\RrsetProviderInterface;
use TowerDNS\Application\DNS\RdataCanonicalizer;
use TowerDNS\Application\DNS\RrsetComparator;
use TowerDNS\Application\Repository\ManagedZoneRepositoryInterface;
use TowerDNS\Application\Repository\ProviderAccountRepositoryInterface;
use TowerDNS\Application\Validation\DNSNameValidator;
use TowerDNS\Application\Validation\RecordValidator;
use TowerDNS\Domain\Account\ManagedZone;
use TowerDNS\Domain\Account\ProviderAccount;
use TowerDNS\Domain\Auth\Permission;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Domain\DNS\DNSRecordType;
use TowerDNS\Domain\DNS\DNSSECProfile;
use TowerDNS\Domain\DNS\Record;
use TowerDNS\Domain\DNS\Rrset;
use TowerDNS\Domain\DNS\Zone;

/** Application operations using ManagedZone as the only local zone identity. */
final readonly class ManagedZoneDNSService
{
    public function __construct(
        private PermissionService $permissions,
        private ManagedZoneRepositoryInterface $managedZones,
        private ProviderAccountRepositoryInterface $providerAccounts,
        private AccountProviderFactoryInterface $providerFactory,
        private ?ResourceLimitService $resourceLimits = null,
    ) {}

    public function create(User $user, int $accountId, int $providerAccountId, string $name): ManagedZone
    {
        $this->permissions->assertAccount($user, Permission::ZONE_CREATE, $accountId);
        $this->resourceLimits?->assertCanCreateZone($accountId);
        $provider = $this->provider($accountId, $providerAccountId, Capability::ZONE_CREATE);
        $zone     = $provider->createZone(DNSNameValidator::normalise($name));
        if (!$zone->active || $zone->id === '') {
            throw new \RuntimeException('Provider returned an invalid zone.');
        }
        try {
            $id = $this->managedZones->create($accountId, $providerAccountId, $zone->id, DNSNameValidator::normalise($zone->name), new \DateTimeImmutable()->format('Y-m-d H:i:s'));
        } catch (\Throwable $e) {
            try {
                $provider->deleteZone($zone->id);
            } catch (\Throwable) { /* provider reconciliation remains possible */
            }
            throw $e;
        }
        $managed = $this->managedZones->findById($id);
        if (!$managed instanceof ManagedZone) {
            throw new \RuntimeException('Managed zone persistence failed.');
        }
        return $managed;
    }

    /** @return list<ManagedZone> */
    public function list(User $user, int $accountId): array
    {
        $this->permissions->assertAccount($user, Permission::ZONE_LIST, $accountId);
        return $this->managedZones->findByAccountId($accountId);
    }

    /** @return list<ProviderAccount> */
    public function availableProviderAccounts(User $user, int $accountId): array
    {
        $this->permissions->assertAccount($user, Permission::ZONE_CREATE, $accountId);
        return array_values(array_filter($this->providerAccounts->findByAccountId($accountId), static fn(ProviderAccount $account): bool => $account->isActive));
    }

    public function delete(User $user, int $accountId, int $managedZoneId): void
    {
        $zone = $this->managedZones->findByIdForAccount($managedZoneId, $accountId);
        if (!$zone instanceof ManagedZone) {
            throw new \DomainException('Managed zone not found.');
        }
        $this->permissions->assertAccount($user, Permission::ZONE_DELETE, $accountId);
        $provider = $this->provider($accountId, $zone->providerAccountId, Capability::ZONE_DELETE);
        $provider->deleteZone($zone->providerZoneId);
        $this->managedZones->delete($zone->id);
    }

    /** @return list<Record> */
    public function listRecords(User $user, int $accountId, int $managedZoneId): array
    {
        [$zone, $provider] = $this->resolve($user, $accountId, $managedZoneId, Permission::RECORD_READ, Capability::RECORD_LIST);
        return $provider->listRecords($zone->providerZoneId);
    }

    /** @return list<Rrset> */
    public function listRrsets(User $user, int $accountId, int $managedZoneId): array
    {
        [$zone, $provider] = $this->resolve($user, $accountId, $managedZoneId, Permission::RECORD_READ, Capability::RECORD_LIST);
        return $this->rrsets($provider)->listRrsets($zone->providerZoneId);
    }

    public function createRecord(User $user, int $accountId, int $managedZoneId, Record $record): Record
    {
        [$zone, $provider] = $this->resolve($user, $accountId, $managedZoneId, Permission::RECORD_CREATE, Capability::RECORD_CREATE);
        RecordValidator::assertTtl($record->ttl);
        RecordValidator::assertContent($record->type, $record->content);
        return $provider->createRecord($this->forProviderZone($record, $zone->providerZoneId));
    }

    public function updateRecord(User $user, int $accountId, int $managedZoneId, Record $record): Record
    {
        [$zone, $provider] = $this->resolve($user, $accountId, $managedZoneId, Permission::RECORD_UPDATE, Capability::RECORD_UPDATE);
        RecordValidator::assertTtl($record->ttl);
        RecordValidator::assertContent($record->type, $record->content);
        return $provider->updateRecord($this->forProviderZone($record, $zone->providerZoneId));
    }

    public function findRecordForUpdate(User $user, int $accountId, int $managedZoneId, string $recordId): ?Record
    {
        [$zone, $provider] = $this->resolve($user, $accountId, $managedZoneId, Permission::RECORD_UPDATE, Capability::RECORD_LIST);
        foreach ($provider->listRecords($zone->providerZoneId) as $record) {
            if ($record->id === $recordId) {
                return $record;
            }
        }
        return null;
    }

    public function deleteRecord(User $user, int $accountId, int $managedZoneId, string $recordId): void
    {
        [$zone, $provider] = $this->resolve($user, $accountId, $managedZoneId, Permission::RECORD_DELETE, Capability::RECORD_DELETE);
        $provider->deleteRecord($zone->providerZoneId, $recordId);
    }

    public function replaceRrset(User $user, int $accountId, int $managedZoneId, Rrset $rrset): Rrset
    {
        [$zone, $provider] = $this->resolve($user, $accountId, $managedZoneId, Permission::RECORD_UPDATE, Capability::RECORD_UPDATE);
        RecordValidator::assertTtl($rrset->ttl);
        foreach ($rrset->rdata as $rdata) {
            RdataCanonicalizer::canonicalize($rrset->type, $rdata);
        }
        $expected = new Rrset($zone->providerZoneId, $rrset->ownerName, $rrset->type, $rrset->ttl, $rrset->rdata, $rrset->providerIdentity, $rrset->metadata);
        $observed = $this->rrsets($provider)->replaceRrset($expected);
        if (!RrsetComparator::equals($expected, $observed)) {
            throw new \RuntimeException('Provider read-back does not match the written RRset.');
        }
        return $observed;
    }

    public function deleteRrset(User $user, int $accountId, int $managedZoneId, string $ownerName, string $type): void
    {
        [$zone, $provider] = $this->resolve($user, $accountId, $managedZoneId, Permission::RECORD_DELETE, Capability::RECORD_DELETE);
        $recordType        = DNSRecordType::parse($type);
        $rrsets            = $this->rrsets($provider);
        $rrsets->deleteRrset($zone->providerZoneId, $ownerName, $recordType->presentation);
        foreach ($rrsets->listRrsets($zone->providerZoneId) as $rrset) {
            if ($rrset->type->equals($recordType) && strcasecmp(rtrim($rrset->ownerName, '.'), rtrim($ownerName, '.')) === 0) {
                throw new \RuntimeException('Provider read-back still contains the deleted RRset.');
            }
        }
    }

    public function dnssecProfile(User $user, int $accountId, int $managedZoneId): DNSSECProfile
    {
        [$zone, $provider] = $this->resolve($user, $accountId, $managedZoneId, Permission::DNSSEC_STATUS_READ, Capability::DNSSEC_STATUS_READ);
        return $provider->getDnssecProfile($zone->providerZoneId);
    }

    /** @param array<string, scalar|array<array-key, scalar>|null> $payload */
    public function executeDnssecAction(User $user, int $accountId, int $managedZoneId, string $action, array $payload = []): DNSSECProfile
    {
        [$zone, $provider] = $this->resolve($user, $accountId, $managedZoneId, Permission::DNSSEC_ACTION_EXECUTE, Capability::DNSSEC_ACTION_EXECUTE);
        return $provider->executeDnssecAction($zone->providerZoneId, $action, $payload);
    }

    /** @return array{0: ManagedZone, 1: DNSProviderInterface} */
    private function resolve(User $user, int $accountId, int $managedZoneId, Permission $permission, string $capability): array
    {
        $zone = $this->managedZones->findByIdForAccount($managedZoneId, $accountId);
        if (!$zone instanceof ManagedZone) {
            throw new \DomainException('Managed zone not found.');
        }
        if (!$this->permissions->authorizeManagedZone($user, $permission, $accountId, $managedZoneId)) {
            throw new \TowerDNS\Application\Exception\AuthorizationException('Managed-zone access denied.');
        }
        return [$zone, $this->provider($accountId, $zone->providerAccountId, $capability)];
    }

    private function provider(int $accountId, int $providerAccountId, string $capability): DNSProviderInterface
    {
        $account = $this->providerAccounts->findById($providerAccountId);
        if (!$account instanceof ProviderAccount || !$account->isActive || $account->accountId !== $accountId) {
            throw new \DomainException('Provider account is not available for this account.');
        }
        $provider = $this->providerFactory->buildProvider($account);
        if (!$provider->capabilities()->supports($capability)) {
            throw new \TowerDNS\Application\Exception\CapabilityException('Provider capability is not supported.');
        }
        return $provider;
    }

    private function rrsets(DNSProviderInterface $provider): RrsetProviderInterface
    {
        if (!$provider instanceof RrsetProviderInterface) {
            throw new \TowerDNS\Application\Exception\CapabilityException('Provider does not support RRset operations.');
        }
        return $provider;
    }

    private function forProviderZone(Record $record, string $providerZoneId): Record
    {
        return new Record($record->id, $providerZoneId, $record->name, $record->type, $record->ttl, $record->content, $record->comment, $record->metadata);
    }
}

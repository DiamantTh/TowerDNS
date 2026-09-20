<?php

declare(strict_types=1);

namespace TowerDNS\Application\Services;

use TowerDNS\Application\Contracts\AccountProviderFactoryInterface;
use TowerDNS\Application\Contracts\Capability;
use TowerDNS\Application\Contracts\DNSProviderInterface;
use TowerDNS\Application\Contracts\ProviderConstraintProviderInterface;
use TowerDNS\Application\Contracts\RrsetProviderInterface;
use TowerDNS\Application\DNS\RdataCanonicalizer;
use TowerDNS\Application\DNS\RrsetComparator;
use TowerDNS\Application\Repository\AccountRepositoryInterface;
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
        private AccountRepositoryInterface $accounts,
        private ManagedZoneRepositoryInterface $managedZones,
        private ProviderAccountRepositoryInterface $providerAccounts,
        private AccountProviderFactoryInterface $providerFactory,
        private ?ResourceLimitService $resourceLimits = null,
    ) {}

    public function create(User $user, int $accountId, int $providerAccountId, string $name): ManagedZone
    {
        $this->permissions->assertAccount($user, Permission::ZONE_CREATE, $accountId);
        $this->assertAccountActive($accountId);
        $this->resourceLimits?->assertCanCreateZone($accountId);
        $provider = $this->provider($accountId, $providerAccountId, Capability::ZONE_CREATE);
        $zone     = $provider->createZone(DNSNameValidator::normalise($name));
        if (!$zone->active || $zone->id === '') {
            throw new \RuntimeException('Provider returned an invalid zone.');
        }
        $id = null;
        try {
            $id = $this->managedZones->create($accountId, $providerAccountId, $zone->id, DNSNameValidator::normalise($zone->name), new \DateTimeImmutable()->format('Y-m-d H:i:s'));

            $managed = $this->managedZones->findById($id);
            if (!$managed instanceof ManagedZone) {
                throw new \RuntimeException('Managed zone persistence failed.');
            }
        } catch (\Throwable $e) {
            $providerZoneDeleted = false;
            try {
                $provider->deleteZone($zone->id);
                $providerZoneDeleted = true;
            } catch (\Throwable) { /* provider reconciliation remains possible */
            }

            if ($providerZoneDeleted && is_int($id)) {
                try {
                    $this->managedZones->delete($id);
                } catch (\Throwable) { /* local reconciliation remains possible */
                }
            }

            throw $e;
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
        $this->assertAccountActive($accountId);
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
        $this->assertAccountActive($accountId);
        RecordValidator::assertTtl($record->ttl);
        $this->assertProviderTtl($provider, $record->ttl);
        $owner   = DNSNameValidator::normaliseRecordOwner($record->name, $zone->canonicalName);
        $content = RecordValidator::normaliseContent($record->type, $record->content);
        return $provider->createRecord($this->forProviderZone($record, $zone->providerZoneId, $owner, $content));
    }

    public function updateRecord(User $user, int $accountId, int $managedZoneId, Record $record): Record
    {
        [$zone, $provider] = $this->resolve($user, $accountId, $managedZoneId, Permission::RECORD_UPDATE, Capability::RECORD_UPDATE);
        $this->assertAccountActive($accountId);
        RecordValidator::assertTtl($record->ttl);
        $this->assertProviderTtl($provider, $record->ttl);
        $owner   = DNSNameValidator::normaliseRecordOwner($record->name, $zone->canonicalName);
        $content = RecordValidator::normaliseContent($record->type, $record->content);
        return $provider->updateRecord($this->forProviderZone($record, $zone->providerZoneId, $owner, $content));
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
        $this->assertAccountActive($accountId);
        $provider->deleteRecord($zone->providerZoneId, $recordId);
    }

    public function replaceRrset(User $user, int $accountId, int $managedZoneId, Rrset $rrset): Rrset
    {
        [$zone, $provider] = $this->resolve($user, $accountId, $managedZoneId, Permission::RECORD_UPDATE, Capability::RECORD_UPDATE);
        $this->assertAccountActive($accountId);
        RecordValidator::assertTtl($rrset->ttl);
        $this->assertProviderTtl($provider, $rrset->ttl);
        $owner = DNSNameValidator::normaliseRecordOwner($rrset->ownerName, $zone->canonicalName);
        $rdata = array_values(array_unique(array_map(
            static fn(string $value): string => RdataCanonicalizer::canonicalize($rrset->type, $value),
            $rrset->rdata,
        )));
        $expected = new Rrset($zone->providerZoneId, $owner, $rrset->type, $rrset->ttl, $rdata, $rrset->providerIdentity, $rrset->metadata);
        $observed = $this->rrsets($provider)->replaceRrset($expected);
        if (!RrsetComparator::equals($expected, $observed)) {
            throw new \RuntimeException('Provider read-back does not match the written RRset.');
        }
        return $observed;
    }

    public function deleteRrset(User $user, int $accountId, int $managedZoneId, string $ownerName, string $type): void
    {
        [$zone, $provider] = $this->resolve($user, $accountId, $managedZoneId, Permission::RECORD_DELETE, Capability::RECORD_DELETE);
        $this->assertAccountActive($accountId);
        $recordType = DNSRecordType::parse($type);
        $ownerName  = DNSNameValidator::normaliseRecordOwner($ownerName, $zone->canonicalName);
        $rrsets     = $this->rrsets($provider);
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
        $profile           = $provider->getDnssecProfile($zone->providerZoneId);

        return new DNSSECProfile(
            $profile->zoneId,
            $profile->state,
            [...$profile->features, 'manual_actions' => $provider->capabilities()->supports(Capability::DNSSEC_ACTION_EXECUTE)],
            $profile->metadata,
        );
    }

    /** @param array<string, scalar|array<array-key, scalar>|null> $payload */
    public function executeDnssecAction(User $user, int $accountId, int $managedZoneId, string $action, array $payload = []): DNSSECProfile
    {
        [$zone, $provider] = $this->resolve($user, $accountId, $managedZoneId, Permission::DNSSEC_ACTION_EXECUTE, Capability::DNSSEC_ACTION_EXECUTE);
        $this->assertAccountActive($accountId);
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

    private function assertAccountActive(int $accountId): void
    {
        $account = $this->accounts->findById($accountId);
        if (!$account instanceof \TowerDNS\Domain\Account\Account || !$account->isActive) {
            throw new \DomainException('Account is inactive.');
        }
    }

    private function rrsets(DNSProviderInterface $provider): RrsetProviderInterface
    {
        if (!$provider instanceof RrsetProviderInterface) {
            throw new \TowerDNS\Application\Exception\CapabilityException('Provider does not support RRset operations.');
        }
        return $provider;
    }

    private function forProviderZone(Record $record, string $providerZoneId, string $ownerName, string $content): Record
    {
        return new Record($record->id, $providerZoneId, $ownerName, $record->type, $record->ttl, $content, $record->comment, $record->metadata);
    }

    private function assertProviderTtl(DNSProviderInterface $provider, int $ttl): void
    {
        if (!$provider instanceof ProviderConstraintProviderInterface) {
            return;
        }

        $minimum = $provider->constraints()->details['minimum_ttl'] ?? null;
        if (is_int($minimum) && $ttl < $minimum) {
            throw new \InvalidArgumentException(sprintf('This provider requires a TTL of at least %d seconds.', $minimum));
        }
    }
}

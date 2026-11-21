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
use TowerDNS\Application\DTO\RrsetListPage;
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
/** @psalm-api DNS application use cases are callable from HTTP, CLI, and provider integrations. */
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

    /** @return array{canCreateZones: bool, canDeleteZones: bool, canManageProviders: bool} */
    public function zoneActions(User $user, int $accountId): array
    {
        $this->permissions->assertAccount($user, Permission::ZONE_LIST, $accountId);
        $active = $this->accounts->findById($accountId)?->isActive === true;
        return [
            'canManageProviders' => $active && $this->permissions->authorizeAccount($user, Permission::PROVIDER_CREDENTIALS_MANAGE, $accountId),
            'canCreateZones'     => $active && $this->permissions->authorizeAccount($user, Permission::ZONE_CREATE, $accountId),
            'canDeleteZones'     => $active && $this->permissions->authorizeAccount($user, Permission::ZONE_DELETE, $accountId),
        ];
    }

    /** @return array<int, string> Display names only; no provider credentials leave this service. */
    public function providerNames(User $user, int $accountId): array
    {
        $this->permissions->assertAccount($user, Permission::ZONE_LIST, $accountId);
        $names = [];
        foreach ($this->providerAccounts->findByAccountId($accountId) as $provider) {
            $names[$provider->id] = $provider->name;
        }
        return $names;
    }

    public function dnssecZoneName(User $user, int $accountId, int $zoneId): string
    {
        if (!$this->permissions->authorizeManagedZone($user, Permission::DNSSEC_STATUS_READ, $accountId, $zoneId)) {
            throw new \TowerDNS\Application\Exception\AuthorizationException('Managed-zone access denied.');
        }
        $zone = $this->managedZones->findByIdForAccount($zoneId, $accountId);
        if (!$zone instanceof ManagedZone) {
            throw new \DomainException('Managed zone not found.');
        }
        return $zone->canonicalName;
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

    public function rrsetListPage(User $user, int $accountId, int $managedZoneId): RrsetListPage
    {
        [$zone, $provider]           = $this->resolve($user, $accountId, $managedZoneId, Permission::RECORD_READ, Capability::RECORD_LIST);
        $rrsets                      = $this->rrsets($provider)->listRrsets($zone->providerZoneId);
        $account                     = $this->accounts->findById($accountId);
        $accountActive               = $account instanceof \TowerDNS\Domain\Account\Account && $account->isActive;
        $providerSupportsRrsetWrites = $provider instanceof RrsetProviderInterface;

        return new RrsetListPage(
            $zone->canonicalName,
            $rrsets,
            $accountActive
                && $providerSupportsRrsetWrites
                && $provider->capabilities()->supports(Capability::RECORD_UPDATE)
                && $this->permissions->authorizeManagedZone($user, Permission::RECORD_UPDATE, $accountId, $managedZoneId),
            $accountActive
                && $providerSupportsRrsetWrites
                && $provider->capabilities()->supports(Capability::RECORD_DELETE)
                && $this->permissions->authorizeManagedZone($user, Permission::RECORD_DELETE, $accountId, $managedZoneId),
            $provider instanceof \TowerDNS\Application\Contracts\ConditionalRrsetProviderInterface,
        );
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

    /** @psalm-suppress PossiblyUnusedReturnValue Returns provider-canonicalized data for read-after-write callers. */
    public function updateRecord(User $user, int $accountId, int $managedZoneId, Record $record): Record
    {
        [$zone, $provider] = $this->resolve($user, $accountId, $managedZoneId, Permission::RECORD_UPDATE, Capability::RECORD_UPDATE);
        $this->assertAccountActive($accountId);
        $this->assertRecordBelongsToZone($provider, $zone->providerZoneId, $record->id);
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
        $this->assertRecordBelongsToZone($provider, $zone->providerZoneId, $recordId);
        $provider->deleteRecord($zone->providerZoneId, $recordId);
    }

    /** @return array{owner: string, ttl: ?int, records: list<string>, version: string, conditional: bool, capabilities: array{conditional_replace: bool, append_record: bool, remove_record: bool, atomic_multi_record: bool, consistency: string}} */
    public function tlsaState(User $user, int $account, int $zoneId, string $owner): array
    {
        [$zone, $provider] = $this->resolve($user, $account, $zoneId, Permission::RECORD_READ, Capability::RECORD_LIST);
        $owner             = $this->tlsaOwner($owner, $zone->canonicalName);
        return $this->tlsaSnapshot($owner, $this->currentTlsa($provider, $zone->providerZoneId, $owner), $provider);
    }

    /**
     * @param list<string> $add
     * @param list<string> $remove
     * @return array{owner: string, ttl: ?int, records: list<string>, version: string, conditional: bool, capabilities: array{conditional_replace: bool, append_record: bool, remove_record: bool, atomic_multi_record: bool, consistency: string}}
     */
    public function changeTlsa(User $user, int $account, int $zoneId, string $owner, array $add, array $remove, string $version, int $ttl): array
    {
        [$zone, $provider] = $this->resolve($user, $account, $zoneId, Permission::RECORD_READ, Capability::RECORD_LIST);
        $this->assertAccountActive($account);
        $conditional = $provider instanceof \TowerDNS\Application\Contracts\ConditionalRrsetProviderInterface;
        $targeted    = $provider instanceof \TowerDNS\Application\Contracts\TargetedRrsetMutationProviderInterface
            ? $provider->rrsetMutationCapabilities()
            : new \TowerDNS\Application\DTO\RrsetMutationCapabilities();
        if (!$conditional && !$targeted->appendRecord && !$targeted->removeRecord) {
            throw new \TowerDNS\Application\Exception\CapabilityException('Provider does not support conditional RRset writes.');
        }
        if ($add !== []) {
            $this->resolve($user, $account, $zoneId, Permission::RECORD_UPDATE, Capability::RECORD_UPDATE);
        }
        if ($remove !== []) {
            $this->resolve($user, $account, $zoneId, Permission::RECORD_DELETE, Capability::RECORD_DELETE);
        }
        $owner     = $this->tlsaOwner($owner, $zone->canonicalName);
        $before    = $this->currentTlsa($provider, $zone->providerZoneId, $owner);
        $snapshot  = $this->tlsaSnapshot($owner, $before, $provider);
        $type      = DNSRecordType::parse('TLSA');
        $canonical = static fn(string $value): string => RdataCanonicalizer::canonicalize($type, $value);
        if (count($add) + count($remove) > 64) {
            throw new \InvalidArgumentException('Too many TLSA values.');
        }
        $add = array_map($canonical, $add);
        foreach ($add as $value) {
            [, , $matching, $association] = explode(' ', $value);
            if (($matching === '1' && strlen($association) !== 64)
                || ($matching === '2' && strlen($association) !== 128)
                || strlen($association) > 131064) {
                throw new \InvalidArgumentException('Invalid TLSA association data length.');
            }
        }
        $remove = array_map($canonical, $remove);
        if (array_intersect($add, $remove) !== []) {
            throw new \InvalidArgumentException('TLSA values cannot be added and removed in the same operation.');
        }
        $records = array_values(array_unique([...array_diff($snapshot['records'], $remove), ...$add]));
        sort($records, SORT_STRING);
        if ($records === $snapshot['records']) {
            return $snapshot;
        }
        if (!hash_equals($snapshot['version'], $version)) {
            throw new \TowerDNS\Application\Exception\RrsetPreconditionException('The TLSA RRset changed. Read it again before retrying.');
        }
        if (!$before instanceof Rrset) {
            $this->resolve($user, $account, $zoneId, Permission::RECORD_CREATE, Capability::RECORD_CREATE);
        }
        $ttl = $before->ttl ?? $ttl;
        RecordValidator::assertTtl($ttl);
        $this->assertProviderTtl($provider, $ttl);
        $after = $records === [] ? null : new Rrset($zone->providerZoneId, $owner, $type, $ttl, $records);
        if ($provider instanceof \TowerDNS\Application\Contracts\ConditionalRrsetProviderInterface) {
            $provider->changeRrsetConditionally($before, $after);
        } else {
            if (!$provider instanceof \TowerDNS\Application\Contracts\TargetedRrsetMutationProviderInterface
                || ($add !== [] && !$targeted->appendRecord)
                || ($remove !== [] && !$targeted->removeRecord)) {
                throw new \TowerDNS\Application\Exception\CapabilityException('Provider does not support the requested targeted TLSA operation.');
            }
            try {
                // Publish additions first: a failed multi-step rollover leaves the old value in place.
                foreach (array_values(array_unique($add)) as $value) {
                    $provider->appendRrsetRecord($zone->providerZoneId, $owner, 'TLSA', $ttl, $value);
                }
                foreach (array_values(array_unique($remove)) as $value) {
                    $provider->removeRrsetRecord($zone->providerZoneId, $owner, 'TLSA', $value);
                }
            } catch (\Throwable $error) {
                // A transport timeout can happen after the provider applied a mutation. Never replay a full RRset replace.
                $observedAfterError = $this->tlsaSnapshot($owner, $this->currentTlsa($provider, $zone->providerZoneId, $owner), $provider);
                if ($observedAfterError['records'] === $records && $observedAfterError['ttl'] === $ttl) {
                    return $observedAfterError;
                }
                throw $error;
            }
        }
        $observed = $this->tlsaSnapshot($owner, $this->currentTlsa($provider, $zone->providerZoneId, $owner), $provider);
        if ($observed['records'] !== $records || $observed['ttl'] !== $ttl) {
            throw new \TowerDNS\Application\Exception\RrsetConflictException('The provider read-back differs. Read again before retrying.');
        }
        return $observed;
    }

    private function tlsaOwner(string $owner, string $zone): string
    {
        $owner = DNSNameValidator::normaliseRecordOwner($owner, $zone);
        if (preg_match('/^_([1-9][0-9]{0,4})\._(?:tcp|udp|sctp)(?:\.[a-z0-9.-]+)?$/D', $owner, $parts) !== 1 || (int) $parts[1] > 65535) {
            throw new \InvalidArgumentException('Invalid TLSA service owner.');
        }
        return $owner;
    }

    private function currentTlsa(DNSProviderInterface $provider, string $zoneId, string $owner): ?Rrset
    {
        foreach ($this->rrsets($provider)->listRrsets($zoneId) as $rrset) {
            if ($rrset->type->presentation === 'TLSA' && $rrset->ownerName === $owner) {
                return $rrset;
            }
        }
        return null;
    }

    /** @return array{owner: string, ttl: ?int, records: list<string>, version: string, conditional: bool, capabilities: array{conditional_replace: bool, append_record: bool, remove_record: bool, atomic_multi_record: bool, consistency: string}} */
    private function tlsaSnapshot(string $owner, ?Rrset $rrset, DNSProviderInterface $provider): array
    {
        $records = $rrset instanceof Rrset ? array_values(array_unique(array_map(
            static fn(string $value): string => RdataCanonicalizer::canonicalize($rrset->type, $value),
            $rrset->rdata,
        ))) : [];
        sort($records, SORT_STRING);
        $ttl       = $rrset?->ttl;
        $mutations = $provider instanceof \TowerDNS\Application\Contracts\TargetedRrsetMutationProviderInterface
            ? $provider->rrsetMutationCapabilities()
            : new \TowerDNS\Application\DTO\RrsetMutationCapabilities();
        return [
            'owner'        => $owner, 'ttl' => $ttl, 'records' => $records,
            'version'      => self::tlsaRevision($owner, $ttl, $records),
            'conditional'  => $provider instanceof \TowerDNS\Application\Contracts\ConditionalRrsetProviderInterface,
            'capabilities' => new \TowerDNS\Application\DTO\RrsetMutationCapabilities(
                conditionalReplace: $provider instanceof \TowerDNS\Application\Contracts\ConditionalRrsetProviderInterface,
                appendRecord: $mutations->appendRecord,
                removeRecord: $mutations->removeRecord,
                atomicMultiRecord: $mutations->atomicMultiRecord || $provider instanceof \TowerDNS\Application\Contracts\ConditionalRrsetProviderInterface,
                consistency: $provider instanceof \TowerDNS\Application\Contracts\ConditionalRrsetProviderInterface ? 'provider_compare_and_set' : $mutations->consistency,
            )->toArray(),
        ];
    }

    public function replaceRrset(User $user, int $accountId, int $managedZoneId, Rrset $rrset, ?string $version = null): Rrset
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
        if ($rrset->type->presentation === 'TLSA') {
            $before = $this->currentTlsa($provider, $zone->providerZoneId, $owner);
            $this->requireTlsaRevision($owner, $before, $provider, $version);
            if (!$before instanceof Rrset) {
                $this->resolve($user, $accountId, $managedZoneId, Permission::RECORD_CREATE, Capability::RECORD_CREATE);
            }
            if ($provider instanceof \TowerDNS\Application\Contracts\ConditionalRrsetProviderInterface) {
                $provider->changeRrsetConditionally($before, $expected);
                $observed = $this->currentTlsa($provider, $zone->providerZoneId, $owner);
            } else {
                // Preflight detects already visible stale forms; providers without CAS retain a documented race window.
                $observed = $this->rrsets($provider)->replaceRrset($expected);
            }
        } else {
            $observed = $this->rrsets($provider)->replaceRrset($expected);
        }
        if (!$observed instanceof Rrset || !RrsetComparator::equals($expected, $observed)) {
            throw new \RuntimeException('Provider read-back does not match the written RRset.');
        }
        return $observed;
    }

    public function deleteRrset(User $user, int $accountId, int $managedZoneId, string $ownerName, string $type, ?string $version = null): void
    {
        [$zone, $provider] = $this->resolve($user, $accountId, $managedZoneId, Permission::RECORD_DELETE, Capability::RECORD_DELETE);
        $this->assertAccountActive($accountId);
        $recordType = DNSRecordType::parse($type);
        $ownerName  = DNSNameValidator::normaliseRecordOwner($ownerName, $zone->canonicalName);
        $rrsets     = $this->rrsets($provider);
        if ($recordType->presentation === 'TLSA') {
            $before = $this->currentTlsa($provider, $zone->providerZoneId, $ownerName);
            $this->requireTlsaRevision($ownerName, $before, $provider, $version);
            if ($before instanceof Rrset) {
                if ($provider instanceof \TowerDNS\Application\Contracts\ConditionalRrsetProviderInterface) {
                    $provider->changeRrsetConditionally($before, null);
                } else {
                    $rrsets->deleteRrset($zone->providerZoneId, $ownerName, $recordType->presentation);
                }
            }
        } else {
            $rrsets->deleteRrset($zone->providerZoneId, $ownerName, $recordType->presentation);
        }
        foreach ($rrsets->listRrsets($zone->providerZoneId) as $rrset) {
            if ($rrset->type->equals($recordType) && strcasecmp(rtrim($rrset->ownerName, '.'), rtrim($ownerName, '.')) === 0) {
                throw new \RuntimeException('Provider read-back still contains the deleted RRset.');
            }
        }
    }

    /** @param list<string> $records */
    public static function tlsaRevision(string $owner, ?int $ttl, array $records): string
    {
        $type   = DNSRecordType::parse('TLSA');
        $values = array_values(array_unique(array_map(
            static fn(string $value): string => RdataCanonicalizer::canonicalize($type, $value),
            $records,
        )));
        sort($values, SORT_STRING);
        return hash('sha256', json_encode([$owner, $ttl, $values], JSON_THROW_ON_ERROR));
    }

    private function requireTlsaRevision(string $owner, ?Rrset $before, DNSProviderInterface $provider, ?string $version): void
    {
        $current = $this->tlsaSnapshot($owner, $before, $provider)['version'];
        if ($version !== null && (hash_equals($current, $version) || ($version === 'new' && !$before instanceof Rrset))) {
            return;
        }
        throw new \TowerDNS\Application\Exception\RrsetPreconditionException('The TLSA RRset changed. Refresh the zone.');
    }

    public function dnssecProfile(User $user, int $accountId, int $managedZoneId): DNSSECProfile
    {
        [$zone, $provider] = $this->resolve($user, $accountId, $managedZoneId, Permission::DNSSEC_STATUS_READ, Capability::DNSSEC_STATUS_READ);
        $profile           = $provider->getDnssecProfile($zone->providerZoneId);

        return new DNSSECProfile(
            $profile->zoneId,
            $profile->state,
            [...$profile->features, 'manual_actions' => $this->accounts->findById($accountId)?->isActive === true && $provider->capabilities()->supports(Capability::DNSSEC_ACTION_EXECUTE) && $this->permissions->authorizeManagedZone($user, Permission::DNSSEC_ACTION_EXECUTE, $accountId, $managedZoneId)],
            $profile->metadata,
        );
    }

    /**
     * @param array<string, scalar|array<array-key, scalar>|null> $payload
     * @psalm-suppress PossiblyUnusedReturnValue Returns updated status for read-after-write callers.
     */
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

    /** Ensure provider-native IDs belong to the already account-scoped zone. */
    private function assertRecordBelongsToZone(DNSProviderInterface $provider, string $providerZoneId, string $recordId): void
    {
        if (!$provider->capabilities()->supports(Capability::RECORD_LIST)) {
            throw new \TowerDNS\Application\Exception\CapabilityException('Provider record listing is required to verify the record scope.');
        }

        foreach ($provider->listRecords($providerZoneId) as $record) {
            if ($record->id === $recordId) {
                return;
            }
        }

        throw new \DomainException('Record not found in this managed zone.');
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

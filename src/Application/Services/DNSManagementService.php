<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Services;

use TowerDNS\Application\Contracts\AccountProviderFactoryInterface;
use TowerDNS\Application\Contracts\Capability;
use TowerDNS\Application\Contracts\DNSProviderInterface;
use TowerDNS\Application\Contracts\RrsetProviderInterface;
use TowerDNS\Application\DNS\RdataCanonicalizer;
use TowerDNS\Application\DNS\RrsetComparator;
use TowerDNS\Application\DTO\ProviderSummaryDTO;
use TowerDNS\Application\Exception\CapabilityException;
use TowerDNS\Application\Provider\ProviderRegistry;
use TowerDNS\Application\Repository\ProviderAccountRepositoryInterface;
use TowerDNS\Application\Validation\DNSNameValidator;
use TowerDNS\Application\Validation\RecordValidator;
use TowerDNS\Domain\Account\ProviderAccount;
use TowerDNS\Domain\Auth\Permission;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Domain\DNS\DNSRecordType;
use TowerDNS\Domain\DNS\DNSSECProfile;
use TowerDNS\Domain\DNS\Record;
use TowerDNS\Domain\DNS\Rrset;
use TowerDNS\Domain\DNS\Zone;

/**
 * Provider-neutral orchestration of DNS workflows.
 *
 * Every public method
 *  1. performs a central RBAC check via {@see AuthorizationService},
 *  2. resolves the addressed provider through the {@see ProviderRegistry},
 *  3. verifies that the provider advertises the required capability,
 *  4. normalises user-supplied input where applicable, and
 *  5. delegates to the provider adapter.
 */
final readonly class DNSManagementService
{
    public function __construct(
        private AuthorizationService $authorizationService,
        private ProviderRegistry $providers,
        private ProviderAccountRepositoryInterface $providerAccounts,
        private AccountProviderFactoryInterface $providerFactory,
        private PermissionService $permissions,
    ) {}

    /**
     * @return list<ProviderSummaryDTO>
     */
    public function listProviders(User $user): array
    {
        $this->authorizationService->assert($user, Permission::ZONE_LIST);

        return $this->providers->summaries();
    }

    /**
     * @return list<Zone>
     */
    public function listZones(User $user, string $providerId): array
    {
        $this->authorizationService->assert($user, Permission::ZONE_LIST);
        $provider = $this->resolve($providerId, Capability::ZONE_LIST);

        return $provider->listZones();
    }

    public function createZone(User $user, string $providerId, string $zoneName): Zone
    {
        $this->authorizationService->assert($user, Permission::ZONE_CREATE);
        $provider = $this->resolve($providerId, Capability::ZONE_CREATE);

        return $provider->createZone(DNSNameValidator::normalise($zoneName));
    }

    public function deleteZone(User $user, string $providerId, string $zoneId): void
    {
        $this->authorizationService->assert($user, Permission::ZONE_DELETE);
        $provider = $this->resolve($providerId, Capability::ZONE_DELETE);

        $provider->deleteZone($zoneId);
    }

    /**
     * @return list<Record>
     */
    public function listRecords(User $user, string $providerId, string $zoneId): array
    {
        $this->authorizationService->assert($user, Permission::RECORD_READ);
        $provider = $this->resolve($providerId, Capability::RECORD_LIST);

        return $provider->listRecords($zoneId);
    }

    /** @return list<Rrset> */
    public function listRrsets(User $user, string $providerId, string $zoneId): array
    {
        $this->authorizationService->assert($user, Permission::RECORD_READ);
        $provider = $this->resolve($providerId, Capability::RECORD_LIST);
        return $this->rrsetProvider($provider)->listRrsets($zoneId);
    }

    /** Writes a complete RRset and verifies it by reading it back from the provider API. */
    public function replaceRrset(User $user, string $providerId, Rrset $rrset): Rrset
    {
        $this->authorizationService->assert($user, Permission::RECORD_UPDATE);
        $provider = $this->resolve($providerId, Capability::RECORD_UPDATE);
        RecordValidator::assertTtl($rrset->ttl);
        foreach ($rrset->rdata as $rdata) {
            RdataCanonicalizer::canonicalize($rrset->type, $rdata);
        }
        $observed = $this->rrsetProvider($provider)->replaceRrset($rrset);
        if (!RrsetComparator::equals($rrset, $observed)) {
            throw new \RuntimeException('Provider-Read-back stimmt nicht mit dem geschriebenen RRset überein.');
        }
        return $observed;
    }

    /** Deletes a complete RRset and confirms that it is absent on read-back. */
    public function deleteRrset(User $user, string $providerId, string $zoneId, string $ownerName, string $type): void
    {
        $this->authorizationService->assert($user, Permission::RECORD_DELETE);
        $provider   = $this->resolve($providerId, Capability::RECORD_DELETE);
        $recordType = DNSRecordType::parse($type);

        $rrsets = $this->rrsetProvider($provider);
        $rrsets->deleteRrset($zoneId, $ownerName, $recordType->presentation);

        foreach ($rrsets->listRrsets($zoneId) as $rrset) {
            if ($rrset->type->equals($recordType)
                && strcasecmp(rtrim($rrset->ownerName, '.'), rtrim($ownerName, '.')) === 0) {
                throw new \RuntimeException('Provider-Read-back enthält das gelöschte RRset weiterhin.');
            }
        }
    }

    public function createRecord(User $user, string $providerId, Record $record): Record
    {
        $this->authorizationService->assert($user, Permission::RECORD_CREATE);
        $provider = $this->resolve($providerId, Capability::RECORD_CREATE);

        RecordValidator::assertTtl($record->ttl);
        RecordValidator::assertContent($record->type, $record->content);

        return $provider->createRecord($record);
    }

    public function updateRecord(User $user, string $providerId, Record $record): Record
    {
        $this->authorizationService->assert($user, Permission::RECORD_UPDATE);
        $provider = $this->resolve($providerId, Capability::RECORD_UPDATE);

        RecordValidator::assertTtl($record->ttl);
        RecordValidator::assertContent($record->type, $record->content);

        return $provider->updateRecord($record);
    }

    /**
     * Resolves an existing record for an update without requiring RECORD_READ.
     * The edit form deliberately does not trust a client-supplied record type.
     */
    public function findRecordForUpdate(User $user, string $providerId, string $zoneId, string $recordId): ?Record
    {
        $this->authorizationService->assert($user, Permission::RECORD_UPDATE);
        $provider = $this->resolve($providerId, Capability::RECORD_LIST);
        foreach ($provider->listRecords($zoneId) as $record) {
            if ($record->id === $recordId) {
                return $record;
            }
        }
        return null;
    }

    public function deleteRecord(User $user, string $providerId, string $zoneId, string $recordId): void
    {
        $this->authorizationService->assert($user, Permission::RECORD_DELETE);
        $provider = $this->resolve($providerId, Capability::RECORD_DELETE);

        $provider->deleteRecord($zoneId, $recordId);
    }

    public function getDnssecProfile(User $user, string $providerId, string $zoneId): DNSSECProfile
    {
        $this->authorizationService->assert($user, Permission::DNSSEC_STATUS_READ);
        $provider = $this->resolve($providerId, Capability::DNSSEC_STATUS_READ);

        return $provider->getDnssecProfile($zoneId);
    }

    /**
     * @param array<string, scalar|array<array-key, scalar>|null> $payload
     */
    public function executeDnssecAction(
        User $user,
        string $providerId,
        string $zoneId,
        string $action,
        array $payload = [],
    ): DNSSECProfile {
        $this->authorizationService->assert($user, Permission::DNSSEC_ACTION_EXECUTE);
        $provider = $this->resolve($providerId, Capability::DNSSEC_ACTION_EXECUTE);

        return $provider->executeDnssecAction($zoneId, $action, $payload);
    }

    // ── ProviderAccount-based operations (multi-tenant) ──────────────────────

    /**
     * @return list<Zone>
     */
    public function listZonesByProviderAccount(User $user, int $accountId, int $providerAccountId): array
    {
        $this->permissions->assertAccount($user, Permission::ZONE_LIST, $accountId);
        $provider = $this->resolveFromAccount($accountId, $providerAccountId, Capability::ZONE_LIST);
        return $provider->listZones();
    }

    public function createZoneInAccount(User $user, int $accountId, int $providerAccountId, string $zoneName): Zone
    {
        $this->permissions->assertAccount($user, Permission::ZONE_CREATE, $accountId);
        $provider = $this->resolveFromAccount($accountId, $providerAccountId, Capability::ZONE_CREATE);
        return $provider->createZone(DNSNameValidator::normalise($zoneName));
    }


    public function deleteZoneInAccount(User $user, int $accountId, int $providerAccountId, string $zoneId): void
    {
        $this->permissions->assertAccount($user, Permission::ZONE_DELETE, $accountId);
        $provider = $this->resolveFromAccount($accountId, $providerAccountId, Capability::ZONE_DELETE);
        $provider->deleteZone($zoneId);
    }

    /**
     * @return list<Record>
     */
    public function listRecordsInAccount(User $user, int $accountId, int $providerAccountId, string $zoneId): array
    {
        $this->permissions->assertZone($user, Permission::RECORD_READ, $accountId, $zoneId);
        $provider = $this->resolveFromAccount($accountId, $providerAccountId, Capability::RECORD_LIST);
        return $provider->listRecords($zoneId);
    }

    public function createRecordInAccount(User $user, int $accountId, int $providerAccountId, Record $record): Record
    {
        $this->permissions->assertZone($user, Permission::RECORD_CREATE, $accountId, $record->zoneId);
        $provider = $this->resolveFromAccount($accountId, $providerAccountId, Capability::RECORD_CREATE);
        RecordValidator::assertTtl($record->ttl);
        RecordValidator::assertContent($record->type, $record->content);
        return $provider->createRecord($record);
    }

    public function updateRecordInAccount(User $user, int $accountId, int $providerAccountId, Record $record): Record
    {
        $this->permissions->assertZone($user, Permission::RECORD_UPDATE, $accountId, $record->zoneId);
        $provider = $this->resolveFromAccount($accountId, $providerAccountId, Capability::RECORD_UPDATE);
        RecordValidator::assertTtl($record->ttl);
        RecordValidator::assertContent($record->type, $record->content);
        return $provider->updateRecord($record);
    }

    public function deleteRecordInAccount(
        User $user,
        int $accountId,
        int $providerAccountId,
        string $zoneId,
        string $recordId,
    ): void {
        $this->permissions->assertZone($user, Permission::RECORD_DELETE, $accountId, $zoneId);
        $provider = $this->resolveFromAccount($accountId, $providerAccountId, Capability::RECORD_DELETE);
        $provider->deleteRecord($zoneId, $recordId);
    }


    private function resolveFromAccount(
        int $accountId,
        int $providerAccountId,
        string $capability,
    ): DNSProviderInterface {
        $pa = $this->providerAccounts->findById($providerAccountId);
        if (!$pa instanceof ProviderAccount || $pa->accountId !== $accountId || !$pa->isActive) {
            throw new \DomainException(sprintf(
                'ProviderAccount %d ist in Account %d nicht verfügbar.',
                $providerAccountId,
                $accountId,
            ));
        }

        $provider = $this->providerFactory->buildProvider($pa);

        if (!$provider->capabilities()->supports($capability)) {
            throw new CapabilityException(sprintf(
                'Provider "%s" unterstuetzt die Capability "%s" nicht.',
                $provider->id(),
                $capability,
            ));
        }

        return $provider;
    }

    private function resolve(string $providerId, string $capability): DNSProviderInterface
    {
        $provider = $this->providers->get($providerId);

        if (!$provider->capabilities()->supports($capability)) {
            throw new CapabilityException(sprintf(
                'Provider "%s" unterstuetzt die Capability "%s" nicht.',
                $provider->id(),
                $capability,
            ));
        }

        return $provider;
    }

    private function rrsetProvider(DNSProviderInterface $provider): RrsetProviderInterface
    {
        if (!$provider instanceof RrsetProviderInterface) {
            throw new CapabilityException(sprintf('Provider "%s" unterstützt keine RRset-Operationen.', $provider->id()));
        }
        return $provider;
    }
}

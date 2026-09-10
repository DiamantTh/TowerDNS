<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Services;

use TowerDNS\Application\Contracts\AccountProviderFactoryInterface;
use TowerDNS\Application\Contracts\Capability;
use TowerDNS\Application\Contracts\DnsProviderInterface;
use TowerDNS\Application\DTO\ProviderSummaryDTO;
use TowerDNS\Application\Exception\CapabilityException;
use TowerDNS\Application\Provider\ProviderRegistry;
use TowerDNS\Application\Repository\ProviderAccountRepositoryInterface;
use TowerDNS\Application\Validation\DnsNameValidator;
use TowerDNS\Application\Validation\RecordValidator;
use TowerDNS\Domain\Account\ProviderAccount;
use TowerDNS\Domain\Auth\Permission;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Domain\DNS\DnssecProfile;
use TowerDNS\Domain\DNS\Record;
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
final readonly class DnsManagementService
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

        return $provider->createZone(DnsNameValidator::normalise($zoneName));
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

    public function deleteRecord(User $user, string $providerId, string $zoneId, string $recordId): void
    {
        $this->authorizationService->assert($user, Permission::RECORD_DELETE);
        $provider = $this->resolve($providerId, Capability::RECORD_DELETE);

        $provider->deleteRecord($zoneId, $recordId);
    }

    public function getDnssecProfile(User $user, string $providerId, string $zoneId): DnssecProfile
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
    ): DnssecProfile {
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
        $provider = $this->resolveFromAccount($accountId, $providerAccountId, $user, Capability::ZONE_LIST);
        return $provider->listZones();
    }

    public function createZoneInAccount(User $user, int $accountId, int $providerAccountId, string $zoneName): Zone
    {
        $this->permissions->assertCanManageAccount($accountId, $user);
        $provider = $this->resolveFromAccount($accountId, $providerAccountId, $user, Capability::ZONE_CREATE);
        return $provider->createZone(DnsNameValidator::normalise($zoneName));
    }

    public function deleteZoneInAccount(User $user, int $accountId, int $providerAccountId, string $zoneId): void
    {
        $this->permissions->assertCanManageAccount($accountId, $user);
        $provider = $this->resolveFromAccount($accountId, $providerAccountId, $user, Capability::ZONE_DELETE);
        $provider->deleteZone($zoneId);
    }

    /**
     * @return list<Record>
     */
    public function listRecordsInAccount(User $user, int $accountId, int $providerAccountId, string $zoneId): array
    {
        $this->permissions->assertCanViewZone($zoneId, $accountId, $user);
        $provider = $this->resolveFromAccount($accountId, $providerAccountId, $user, Capability::RECORD_LIST);
        return $provider->listRecords($zoneId);
    }

    public function createRecordInAccount(User $user, int $accountId, int $providerAccountId, Record $record): Record
    {
        $this->permissions->assertCanManageZoneRecords($record->zoneId, $accountId, $user);
        $provider = $this->resolveFromAccount($accountId, $providerAccountId, $user, Capability::RECORD_CREATE);
        RecordValidator::assertTtl($record->ttl);
        RecordValidator::assertContent($record->type, $record->content);
        return $provider->createRecord($record);
    }

    public function updateRecordInAccount(User $user, int $accountId, int $providerAccountId, Record $record): Record
    {
        $this->permissions->assertCanManageZoneRecords($record->zoneId, $accountId, $user);
        $provider = $this->resolveFromAccount($accountId, $providerAccountId, $user, Capability::RECORD_UPDATE);
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
        $this->permissions->assertCanManageZoneRecords($zoneId, $accountId, $user);
        $provider = $this->resolveFromAccount($accountId, $providerAccountId, $user, Capability::RECORD_DELETE);
        $provider->deleteRecord($zoneId, $recordId);
    }

    private function resolveFromAccount(
        int $accountId,
        int $providerAccountId,
        User $user,
        string $capability,
    ): DnsProviderInterface {
        $this->permissions->assertCanViewAccount($accountId, $user);

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

    private function resolve(string $providerId, string $capability): DnsProviderInterface
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
}

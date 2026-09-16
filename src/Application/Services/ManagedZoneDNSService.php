<?php

declare(strict_types=1);

namespace TowerDNS\Application\Services;

use TowerDNS\Application\Contracts\AccountProviderFactoryInterface;
use TowerDNS\Application\Contracts\Capability;
use TowerDNS\Application\Contracts\DNSProviderInterface;
use TowerDNS\Application\Repository\ManagedZoneRepositoryInterface;
use TowerDNS\Application\Repository\ProviderAccountRepositoryInterface;
use TowerDNS\Application\Validation\DNSNameValidator;
use TowerDNS\Domain\Account\ManagedZone;
use TowerDNS\Domain\Account\ProviderAccount;
use TowerDNS\Domain\Auth\Permission;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Domain\DNS\Record;
use TowerDNS\Domain\DNS\Zone;

/** Application operations using ManagedZone as the only local zone identity. */
final readonly class ManagedZoneDNSService
{
    public function __construct(
        private PermissionService $permissions,
        private ManagedZoneRepositoryInterface $managedZones,
        private ProviderAccountRepositoryInterface $providerAccounts,
        private AccountProviderFactoryInterface $providerFactory,
    ) {}

    public function create(User $user, int $accountId, int $providerAccountId, string $name): ManagedZone
    {
        $this->permissions->assertAccount($user, Permission::ZONE_CREATE, $accountId);
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

    /** @return array{0: ManagedZone, 1: DNSProviderInterface} */
    private function resolve(User $user, int $accountId, int $managedZoneId, Permission $permission, string $capability): array
    {
        $zone = $this->managedZones->findByIdForAccount($managedZoneId, $accountId);
        if (!$zone instanceof ManagedZone) {
            throw new \DomainException('Managed zone not found.');
        }
        $this->permissions->authorizeManagedZone($user, $permission, $accountId, $managedZoneId)
            || throw new \TowerDNS\Application\Exception\AuthorizationException('Managed-zone access denied.');
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
}

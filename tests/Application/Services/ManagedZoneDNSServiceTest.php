<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Application\Services;

use PHPUnit\Framework\TestCase;
use TowerDNS\Application\Contracts\AccountProviderFactoryInterface;
use TowerDNS\Application\Contracts\Capability;
use TowerDNS\Application\Contracts\DNSProviderInterface;
use TowerDNS\Application\Contracts\ProviderCapabilitySet;
use TowerDNS\Application\Contracts\ProviderConstraintProfile;
use TowerDNS\Application\Contracts\ProviderConstraintProviderInterface;
use TowerDNS\Application\Contracts\RrsetProviderInterface;
use TowerDNS\Application\Exception\CapabilityException;
use TowerDNS\Application\Repository\AccountRepositoryInterface;
use TowerDNS\Application\Repository\ManagedZoneRepositoryInterface;
use TowerDNS\Application\Repository\ProviderAccountRepositoryInterface;
use TowerDNS\Application\Repository\ZoneMembershipRepositoryInterface;
use TowerDNS\Application\Services\AuthorizationService;
use TowerDNS\Application\Services\ManagedZoneDNSService;
use TowerDNS\Application\Services\PermissionService;
use TowerDNS\Application\Services\RbacPermissionChecker;
use TowerDNS\Domain\Account\Account;
use TowerDNS\Domain\Account\ManagedZone;
use TowerDNS\Domain\Account\ProviderAccount;
use TowerDNS\Domain\Account\TeamRole;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Domain\DNS\DNSRecordType;
use TowerDNS\Domain\DNS\DNSSECProfile;
use TowerDNS\Domain\DNS\DNSSECState;
use TowerDNS\Domain\DNS\Record;
use TowerDNS\Domain\DNS\RecordType;
use TowerDNS\Domain\DNS\Rrset;
use TowerDNS\Domain\DNS\Zone;

final class ManagedZoneDNSServiceTest extends TestCase
{
    public function testDnssecProfileReflectsProviderActionCapability(): void
    {
        $managed = new ManagedZone(7, 42, 9, 'provider-zone', 'example.test', '2026-09-17 12:00:00');
        $zones   = $this->createMock(ManagedZoneRepositoryInterface::class);
        $zones->method('findByIdForAccount')->with(7, 42)->willReturn($managed);

        $providerAccounts = $this->createMock(ProviderAccountRepositoryInterface::class);
        $providerAccounts->method('findById')->with(9)->willReturn(new ProviderAccount(9, 42, 'fake', 'Fake', 'ciphertext', 3, true, '2026-09-17 12:00:00'));

        $provider = $this->createMock(DNSProviderInterface::class);
        $provider->method('capabilities')->willReturn(new ProviderCapabilitySet([
            Capability::DNSSEC_STATUS_READ    => true,
            Capability::DNSSEC_ACTION_EXECUTE => false,
        ]));
        $provider->method('getDnssecProfile')->with('provider-zone')->willReturn(
            new DNSSECProfile('provider-zone', DNSSECState::SIGNED, ['manual_actions' => true]),
        );

        $factory = $this->createMock(AccountProviderFactoryInterface::class);
        $factory->method('buildProvider')->with(self::isInstanceOf(ProviderAccount::class))->willReturn($provider);

        $accounts = $this->createMock(AccountRepositoryInterface::class);
        $accounts->method('getEffectiveRole')->with(42, 'user')->willReturn(TeamRole::DNS_MANAGER);
        $permissions = new PermissionService(
            $accounts,
            $this->createMock(ZoneMembershipRepositoryInterface::class),
            new AuthorizationService(new RbacPermissionChecker()),
            new RbacPermissionChecker(),
            $zones,
        );
        $service = new ManagedZoneDNSService($permissions, $accounts, $zones, $providerAccounts, $factory);

        $profile = $service->dnssecProfile(new User('user', 'user@example.test'), 42, 7);

        self::assertSame(DNSSECState::SIGNED, $profile->state);
        self::assertFalse($profile->features['manual_actions']);
    }

    public function testRrsetPageExposesOnlyPermittedProviderSupportedActions(): void
    {
        $provider = new RecordingDNSProvider([
            Capability::RECORD_LIST   => true,
            Capability::RECORD_UPDATE => false,
            Capability::RECORD_DELETE => true,
        ]);
        $view = $this->serviceForZone($provider)->rrsetListPage(new User('owner', 'owner@example.test'), 42, 7);

        self::assertSame('example.org', $view->managedZoneName);
        self::assertFalse($view->canReplaceRrsets);
        self::assertTrue($view->canDeleteRrsets);
    }

    public function testRrsetPageDisablesMutationsForReadOnlyMemberships(): void
    {
        $provider = new RecordingDNSProvider([
            Capability::RECORD_LIST   => true,
            Capability::RECORD_UPDATE => true,
            Capability::RECORD_DELETE => true,
        ]);
        $view = $this->serviceForZone($provider, role: TeamRole::VIEWER)->rrsetListPage(new User('owner', 'owner@example.test'), 42, 7);

        self::assertFalse($view->canReplaceRrsets);
        self::assertFalse($view->canDeleteRrsets);
    }

    public function testMutationsAreBlockedForInactiveAccounts(): void
    {
        $accounts = $this->createMock(AccountRepositoryInterface::class);
        $accounts->method('getEffectiveRole')->with(42, 'user')->willReturn(TeamRole::OWNER);
        $accounts->method('findById')->with(42)->willReturn(new Account(42, 'Disabled', 'disabled', 'user', false, '2026-09-17 00:00:00'));
        $zones            = $this->createMock(ManagedZoneRepositoryInterface::class);
        $providerAccounts = $this->createMock(ProviderAccountRepositoryInterface::class);
        $providerAccounts->expects(self::never())->method('findById');
        $factory     = $this->createMock(AccountProviderFactoryInterface::class);
        $permissions = new PermissionService($accounts, $this->createMock(ZoneMembershipRepositoryInterface::class), new AuthorizationService(new RbacPermissionChecker()), new RbacPermissionChecker(), $zones);
        $service     = new ManagedZoneDNSService($permissions, $accounts, $zones, $providerAccounts, $factory);

        $this->expectException(\DomainException::class);
        $service->create(new User('user', 'user@example.test'), 42, 9, 'example.test');
    }

    public function testRecordOperationResolvesOnlyTheManagedZoneProviderReference(): void
    {
        $managed = new ManagedZone(7, 42, 9, 'same-external-id', 'example.test', '2026-09-17 12:00:00');
        $zones   = $this->createMock(ManagedZoneRepositoryInterface::class);
        $zones->expects(self::exactly(2))->method('findByIdForAccount')->with(7, 42)->willReturn($managed);
        $providerAccounts = $this->createMock(ProviderAccountRepositoryInterface::class);
        $providerAccounts->expects(self::once())->method('findById')->with(9)->willReturn(new ProviderAccount(9, 42, 'fake', 'Fake', 'ciphertext', 3, true, '2026-09-17 12:00:00'));
        $provider = new class implements DNSProviderInterface {
            public ?Record $created = null;
            public function id(): string
            {
                return 'fake';
            }
            public function displayName(): string
            {
                return 'Fake';
            }
            public function capabilities(): ProviderCapabilitySet
            {
                return new ProviderCapabilitySet([Capability::RECORD_CREATE => true]);
            }
            public function listZones(): array
            {
                return [];
            }
            public function createZone(string $zoneName): Zone
            {
                return new Zone('', $zoneName, 'fake', true);
            }
            public function deleteZone(string $zoneId): void {}
            public function listRecords(string $zoneId): array
            {
                return [];
            }
            public function createRecord(Record $record): Record
            {
                return $this->created = $record;
            }
            public function updateRecord(Record $record): Record
            {
                return $record;
            }
            public function deleteRecord(string $zoneId, string $recordId): void {}
            public function getDnssecProfile(string $zoneId): DNSSECProfile
            {
                return new DNSSECProfile($zoneId, DNSSECState::UNKNOWN);
            }
            public function executeDnssecAction(string $zoneId, string $action, array $payload = []): DNSSECProfile
            {
                return new DNSSECProfile($zoneId, DNSSECState::UNKNOWN);
            }
        };
        $factory = $this->createMock(AccountProviderFactoryInterface::class);
        $factory->expects(self::once())->method('buildProvider')->willReturn($provider);
        $accounts = $this->createMock(AccountRepositoryInterface::class);
        $accounts->method('getEffectiveRole')->with(42, 'user')->willReturn(TeamRole::DNS_MANAGER);
        $accounts->method('findById')->with(42)->willReturn(new Account(42, 'Team', 'team', 'user', true, '2026-09-17 00:00:00'));
        $memberships = $this->createMock(ZoneMembershipRepositoryInterface::class);
        $rbac        = new RbacPermissionChecker();
        $permissions = new PermissionService($accounts, $memberships, new AuthorizationService($rbac), $rbac, $zones);
        $service     = new ManagedZoneDNSService($permissions, $accounts, $zones, $providerAccounts, $factory);

        $service->createRecord(new User('user', 'user@example.test'), 42, 7, new Record('', 'untrusted-zone', 'www', RecordType::A, 300, '192.0.2.1'));

        self::assertSame('same-external-id', $provider->created?->zoneId);
    }

    public function testRecordMutationRejectsOutOfZoneAbsoluteOwnerNames(): void
    {
        $provider = new RecordingDNSProvider([Capability::RECORD_CREATE => true]);
        $service  = $this->serviceForZone($provider);

        try {
            $service->createRecord(
                new User('owner', 'owner@example.test'),
                42,
                7,
                new Record('', '', 'other.example.net.', RecordType::A, 300, '192.0.2.10'),
            );
            self::fail('An out-of-zone absolute owner name should be rejected.');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $provider->createdRecords);
        }
    }

    public function testRecordMutationNormalizesOwnerAndRdataBeforeProviderCall(): void
    {
        $provider = new RecordingDNSProvider([Capability::RECORD_CREATE => true]);
        $service  = $this->serviceForZone($provider);

        $service->createRecord(
            new User('owner', 'owner@example.test'),
            42,
            7,
            new Record('', '', 'WWW.Example.org.', RecordType::AAAA, 300, '2001:0DB8:0:0::1'),
        );

        self::assertCount(1, $provider->createdRecords);
        self::assertSame('provider-zone', $provider->createdRecords[0]->zoneId);
        self::assertSame('www', $provider->createdRecords[0]->name);
        self::assertSame('2001:db8::1', $provider->createdRecords[0]->content);
    }

    public function testManipulatedAccountAndManagedZoneIdsCannotCrossTenantScope(): void
    {
        $provider = new RecordingDNSProvider([Capability::RECORD_CREATE => true]);
        $service  = $this->serviceForZone($provider);

        try {
            $service->createRecord(
                new User('owner', 'owner@example.test'),
                99,
                7,
                new Record('', '', 'www', RecordType::A, 300, '192.0.2.10'),
            );
            self::fail('A managed zone must be resolved inside the supplied account.');
        } catch (\DomainException) {
            self::assertSame([], $provider->createdRecords);
        }
    }

    public function testInactiveProviderAccountAndUnsupportedOperationAreRejectedBeforeMutation(): void
    {
        $provider = new RecordingDNSProvider([Capability::RECORD_CREATE => true]);
        $service  = $this->serviceForZone($provider, providerActive: false);
        try {
            $service->createRecord(new User('owner', 'owner@example.test'), 42, 7, new Record('', '', 'www', RecordType::A, 300, '192.0.2.10'));
            self::fail('An inactive provider account must not perform DNS operations.');
        } catch (\DomainException) {
            self::assertSame([], $provider->createdRecords);
        }

        $unsupported = new RecordingDNSProvider([Capability::RECORD_CREATE => false]);
        $service     = $this->serviceForZone($unsupported);
        try {
            $service->createRecord(new User('owner', 'owner@example.test'), 42, 7, new Record('', '', 'www', RecordType::A, 300, '192.0.2.10'));
            self::fail('An unsupported provider operation must be blocked.');
        } catch (CapabilityException) {
            self::assertSame([], $unsupported->createdRecords);
        }
    }

    public function testProviderAccountFromAnotherTenantIsRejectedBeforeMutation(): void
    {
        $provider = new RecordingDNSProvider([Capability::RECORD_CREATE => true]);
        $service  = $this->serviceForZone($provider, providerAccountTenant: 99);

        try {
            $service->createRecord(new User('owner', 'owner@example.test'), 42, 7, new Record('', '', 'www', RecordType::A, 300, '192.0.2.10'));
            self::fail('A provider account from another tenant must never be used for DNS mutations.');
        } catch (\DomainException) {
            self::assertSame([], $provider->createdRecords);
        }
    }

    public function testProviderTtlConstraintIsEnforcedBeforeTheMutation(): void
    {
        $provider = new RecordingDNSProvider([Capability::RECORD_CREATE => true], ['minimum_ttl' => 60]);
        $service  = $this->serviceForZone($provider);

        try {
            $service->createRecord(new User('owner', 'owner@example.test'), 42, 7, new Record('', '', 'www', RecordType::A, 30, '192.0.2.10'));
            self::fail('The provider minimum TTL must be enforced by the application service.');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $provider->createdRecords);
        }
    }

    public function testUpdateRejectsAProviderRecordIdNotPresentInTheScopedZone(): void
    {
        $provider                = new RecordingDNSProvider([Capability::RECORD_LIST => true, Capability::RECORD_UPDATE => true]);
        $provider->listedRecords = [new Record('record-in-another-zone', 'different-provider-zone', 'www', RecordType::A, 300, '192.0.2.1')];
        $service                 = $this->serviceForZone($provider);

        try {
            $service->updateRecord(
                new User('owner', 'owner@example.test'),
                42,
                7,
                new Record('record-in-another-zone', '', 'www', RecordType::A, 300, '192.0.2.2'),
            );
            self::fail('An external record ID not returned for the scoped provider zone must be rejected.');
        } catch (\DomainException) {
            self::assertSame([], $provider->updatedRecords);
        }
    }

    public function testDeleteRejectsAProviderRecordIdNotPresentInTheScopedZone(): void
    {
        $provider                = new RecordingDNSProvider([Capability::RECORD_LIST => true, Capability::RECORD_DELETE => true]);
        $provider->listedRecords = [new Record('record-in-another-zone', 'different-provider-zone', 'www', RecordType::A, 300, '192.0.2.1')];
        $service                 = $this->serviceForZone($provider);

        try {
            $service->deleteRecord(new User('owner', 'owner@example.test'), 42, 7, 'record-in-another-zone');
            self::fail('An external record ID not returned for the scoped provider zone must be rejected.');
        } catch (\DomainException) {
            self::assertSame([], $provider->deletedRecords);
        }
    }

    public function testUpdateAndDeleteProceedOnlyForAnIdReturnedByTheScopedZone(): void
    {
        $provider                = new RecordingDNSProvider([Capability::RECORD_LIST => true, Capability::RECORD_UPDATE => true, Capability::RECORD_DELETE => true]);
        $provider->listedRecords = [new Record('scoped-record', 'provider-zone', 'www', RecordType::A, 300, '192.0.2.1')];
        $service                 = $this->serviceForZone($provider);
        $user                    = new User('owner', 'owner@example.test');

        $service->updateRecord($user, 42, 7, new Record('scoped-record', '', 'www', RecordType::A, 300, '192.0.2.2'));
        $service->deleteRecord($user, 42, 7, 'scoped-record');

        self::assertSame('provider-zone', $provider->updatedRecords[0]->zoneId);
        self::assertSame(['scoped-record'], $provider->deletedRecords);
    }

    public function testRrsetReplaceCanonicalizesMultipleValuesAndVerifiesProviderReadback(): void
    {
        $provider = new RecordingDNSProvider([
            Capability::RECORD_LIST   => true,
            Capability::RECORD_UPDATE => true,
        ]);
        $provider->rrsetReadback = new Rrset(
            'provider-zone',
            'www',
            DNSRecordType::parse('A'),
            300,
            ['192.0.2.2', '192.0.2.1'],
        );
        $service = $this->serviceForZone($provider);

        $result = $service->replaceRrset(
            new User('owner', 'owner@example.test'),
            42,
            7,
            new Rrset('', 'WWW.Example.org.', DNSRecordType::parse('a'), 300, ['192.0.2.1', '192.0.2.2', '192.0.2.1']),
        );

        $submitted = $provider->replacedRrset;
        self::assertNotNull($submitted);
        self::assertSame('provider-zone', $submitted->zoneId);
        self::assertSame('www', $submitted->ownerName);
        self::assertSame(['192.0.2.1', '192.0.2.2'], $submitted->rdata);
        self::assertSame(['192.0.2.2', '192.0.2.1'], $result->rdata);
    }

    public function testProviderZoneIsCompensatedWhenLocalPersistenceReadbackFails(): void
    {
        $provider = new RecordingDNSProvider([Capability::ZONE_CREATE => true]);
        $user     = new User('owner', 'owner@example.test');

        $zones = $this->createMock(ManagedZoneRepositoryInterface::class);
        $zones->expects(self::once())->method('create')->willReturn(17);
        $zones->expects(self::once())->method('findById')->with(17)->willReturn(null);
        $zones->expects(self::once())->method('delete')->with(17);

        $accounts = $this->createMock(AccountRepositoryInterface::class);
        $accounts->method('findById')->with(42)->willReturn(new Account(42, 'Example', 'example', 'owner', true, '2026-09-17 00:00:00'));
        $accounts->method('getEffectiveRole')->with(42, 'owner')->willReturn(TeamRole::OWNER);

        $providerAccount  = new ProviderAccount(9, 42, 'fake', 'Fake', 'ciphertext', 3, true, '2026-09-17 12:00:00');
        $providerAccounts = $this->createMock(ProviderAccountRepositoryInterface::class);
        $providerAccounts->method('findById')->with(9)->willReturn($providerAccount);

        $factory = $this->createMock(AccountProviderFactoryInterface::class);
        $factory->method('buildProvider')->with($providerAccount)->willReturn($provider);

        $rbac        = new RbacPermissionChecker();
        $permissions = new PermissionService($accounts, $this->createMock(ZoneMembershipRepositoryInterface::class), new AuthorizationService($rbac), $rbac, $zones);
        $service     = new ManagedZoneDNSService($permissions, $accounts, $zones, $providerAccounts, $factory);

        try {
            $service->create($user, 42, 9, 'example.org');
            self::fail('A failed local read-back must fail the zone creation operation.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Managed zone persistence failed.', $exception->getMessage());
            self::assertSame(['provider-zone'], $provider->deletedZones);
        }
    }

    private function serviceForZone(RecordingDNSProvider $provider, bool $providerActive = true, int $providerAccountTenant = 42, TeamRole $role = TeamRole::OWNER): ManagedZoneDNSService
    {
        $zone  = new ManagedZone(7, 42, 9, 'provider-zone', 'example.org', '2026-09-17 12:00:00');
        $zones = $this->createMock(ManagedZoneRepositoryInterface::class);
        $zones->method('findByIdForAccount')->willReturnCallback(static fn(int $id, int $accountId): ?ManagedZone => $id === $zone->id && $accountId === $zone->accountId ? $zone : null);

        $accounts = $this->createMock(AccountRepositoryInterface::class);
        $accounts->method('findById')->willReturnCallback(static fn(int $id): ?Account => $id === 42 ? new Account(42, 'Example', 'example', 'owner', true, '2026-09-17 00:00:00') : null);
        $accounts->method('getEffectiveRole')->willReturn($role);

        $providerAccount  = new ProviderAccount(9, $providerAccountTenant, 'fake', 'Fake', 'ciphertext', 3, $providerActive, '2026-09-17 12:00:00');
        $providerAccounts = $this->createMock(ProviderAccountRepositoryInterface::class);
        $providerAccounts->method('findById')->willReturnCallback(static fn(int $id): ?ProviderAccount => $id === $providerAccount->id ? $providerAccount : null);

        $factory = $this->createMock(AccountProviderFactoryInterface::class);
        $factory->method('buildProvider')->willReturn($provider);

        $rbac        = new RbacPermissionChecker();
        $permissions = new PermissionService($accounts, $this->createMock(ZoneMembershipRepositoryInterface::class), new AuthorizationService($rbac), $rbac, $zones);
        return new ManagedZoneDNSService($permissions, $accounts, $zones, $providerAccounts, $factory);
    }
}

/** Small provider spy for application-service behavior tests. */
final class RecordingDNSProvider implements DNSProviderInterface, ProviderConstraintProviderInterface, RrsetProviderInterface
{
    /** @var list<Record> */
    public array $createdRecords = [];

    /** @var list<string> */
    public array $deletedZones = [];

    /** @var list<Record> */
    public array $listedRecords = [];

    /** @var list<Record> */
    public array $updatedRecords = [];

    /** @var list<string> */
    public array $deletedRecords = [];

    public ?Rrset $replacedRrset = null;

    public ?Rrset $rrsetReadback = null;

    /** @param array<string, bool> $capabilities
     *  @param array<string, scalar|list<string>> $constraintDetails
     */
    public function __construct(private readonly array $capabilities, private readonly array $constraintDetails = []) {}

    public function id(): string
    {
        return 'fake';
    }
    public function displayName(): string
    {
        return 'Fake';
    }
    public function capabilities(): ProviderCapabilitySet
    {
        return new ProviderCapabilitySet($this->capabilities);
    }
    public function constraints(): ProviderConstraintProfile
    {
        return new ProviderConstraintProfile('test', 'immediate', false, $this->constraintDetails);
    }
    public function listZones(): array
    {
        return [];
    }
    public function createZone(string $zoneName): Zone
    {
        return new Zone('provider-zone', $zoneName, 'fake', true);
    }
    public function deleteZone(string $zoneId): void
    {
        $this->deletedZones[] = $zoneId;
    }
    public function listRecords(string $zoneId): array
    {
        return array_values(array_filter($this->listedRecords, static fn(Record $record): bool => $record->zoneId === $zoneId));
    }
    public function createRecord(Record $record): Record
    {
        $this->createdRecords[] = $record;
        return $record;
    }
    public function updateRecord(Record $record): Record
    {
        $this->updatedRecords[] = $record;
        return $record;
    }
    public function deleteRecord(string $zoneId, string $recordId): void
    {
        $this->deletedRecords[] = $recordId;
    }
    public function listRrsets(string $zoneId): array
    {
        return [];
    }
    public function replaceRrset(Rrset $rrset): Rrset
    {
        $this->replacedRrset = $rrset;
        return $this->rrsetReadback ?? $rrset;
    }
    public function deleteRrset(string $zoneId, string $ownerName, string $type): void {}
    public function getDnssecProfile(string $zoneId): DNSSECProfile
    {
        return new DNSSECProfile($zoneId, DNSSECState::UNKNOWN);
    }
    public function executeDnssecAction(string $zoneId, string $action, array $payload = []): DNSSECProfile
    {
        return new DNSSECProfile($zoneId, DNSSECState::UNKNOWN);
    }
}

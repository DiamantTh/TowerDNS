<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Application\Services;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use TowerDNS\Application\Services\ActiveAccountService;
use TowerDNS\Application\Services\UserLifecycleService;
use TowerDNS\Domain\Account\AccountKind;
use TowerDNS\Infrastructure\Clock\SystemClock;
use TowerDNS\Infrastructure\Persistence\DbalAccountRepository;
use TowerDNS\Infrastructure\Persistence\DbalAccountResourceLimitsRepository;
use TowerDNS\Infrastructure\Persistence\DbalManagedZoneRepository;
use TowerDNS\Infrastructure\Persistence\DbalProviderAccountRepository;
use TowerDNS\Infrastructure\Persistence\DbalTransactionRunner;
use TowerDNS\Infrastructure\Persistence\DbalUserRepository;
use TowerDNS\Infrastructure\Persistence\SchemaManager;
use TowerDNS\Infrastructure\Persistence\SqliteConnectionConfigurator;

final class UserLifecycleServiceTest extends TestCase
{
    private Connection $connection;
    private UserLifecycleService $lifecycle;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        new SqliteConnectionConfigurator()->configure($this->connection);
        new SchemaManager($this->connection)->createTablesIfNotExist();
        $this->lifecycle = new UserLifecycleService(
            new DbalTransactionRunner($this->connection),
            new DbalUserRepository($this->connection, new SystemClock()),
            new DbalAccountRepository($this->connection),
            new DbalAccountResourceLimitsRepository($this->connection),
            new DbalManagedZoneRepository($this->connection),
            new DbalProviderAccountRepository($this->connection),
        );
    }

    public function testCreationAndRetryProduceExactlyOneOwnedPersonalAccountWithLimits(): void
    {
        $userId  = 'b9622622-1000-4000-8000-000000000001';
        $account = $this->lifecycle->create($userId, 'person@example.test', 'hash');
        self::assertSame($userId, $account->ownerUserId);
        self::assertSame('personal-' . $userId, $account->slug);
        self::assertSame('personal', $account->kind()->value);
        self::assertSame(AccountKind::PERSONAL, $account->type);
        self::assertSame($userId, $account->personalUserId);
        self::assertSame($account->id, $this->lifecycle->ensurePersonalAccount($userId)->id);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM accounts'));
        self::assertSame('owner', $this->connection->fetchOne('SELECT role FROM account_memberships WHERE account_id = ?', [$account->id]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM account_resource_limits WHERE account_id = ?', [$account->id]));
    }

    public function testAccountCreationFailureRollsBackNewUser(): void
    {
        $otherId = 'b9622622-1000-4000-8000-000000000002';
        $newId   = 'b9622622-1000-4000-8000-000000000003';
        $this->lifecycle->create($otherId, 'other@example.test', 'hash');
        new DbalAccountRepository($this->connection)->create('Collision', 'personal-' . $newId, $otherId, '2026-09-19 12:00:00');

        try {
            $this->lifecycle->create($newId, 'new@example.test', 'hash');
            self::fail('The reserved slug must reject the account insert.');
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM users WHERE id = ?', [$newId]));
        }
    }

    public function testDeletionRefusesOwnedOrganization(): void
    {
        $userId = 'b9622622-1000-4000-8000-000000000004';
        $this->lifecycle->create($userId, 'owner@example.test', 'hash');
        new DbalAccountRepository($this->connection)->create('Organization', 'organization', $userId, '2026-09-19 12:00:00');

        $this->expectException(\DomainException::class);
        $this->lifecycle->delete($userId);
    }

    public function testDeletionRefusesPersonalResourcesAndCanRemoveEmptyAccount(): void
    {
        $userId     = 'b9622622-1000-4000-8000-000000000005';
        $account    = $this->lifecycle->create($userId, 'resources@example.test', 'hash');
        $providers  = new DbalProviderAccountRepository($this->connection);
        $providerId = $providers->create($account->id, 'test', 'Connection', 'encrypted-placeholder', 1, '2026-09-19 12:00:00');
        try {
            $this->lifecycle->delete($userId);
            self::fail('A personal account with resources must not be deleted.');
        } catch (\DomainException) {
            self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM users WHERE id = ?', [$userId]));
        }
        $providers->delete($providerId, $account->id);
        $this->lifecycle->delete($userId);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM users WHERE id = ?', [$userId]));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM accounts WHERE id = ?', [$account->id]));
    }

    public function testActiveAccountDefaultsToPersonalWithoutGrantingMembership(): void
    {
        $userId = 'b9622622-1000-4000-8000-000000000006';
        $this->lifecycle->create($userId, 'member@example.test', 'hash');
        $accounts       = new DbalAccountRepository($this->connection);
        $organizationId = $accounts->create('AAA Team', 'aaa-team', $userId, '2026-09-19 12:00:00');
        $user           = new DbalUserRepository($this->connection, new SystemClock())->findById($userId);
        self::assertNotNull($user);

        $active = new ActiveAccountService($accounts, $this->lifecycle);
        self::assertSame('personal-' . $userId, $active->defaultFor($user)?->slug);
        self::assertSame($organizationId, $active->select($user, $organizationId)->id);
        self::assertNull($active->resolve($user, 999999));
        self::assertCount(1, $accounts->findByUserId($userId, 'AAA', AccountKind::ORGANIZATION));
        self::assertCount(1, $accounts->findByUserId($userId, null, AccountKind::PERSONAL, 1, 0));
    }
}

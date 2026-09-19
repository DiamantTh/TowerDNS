<?php

declare(strict_types=1);

namespace TowerDNSTestsApplicationServices;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use TowerDNS\Application\Repository\ZoneMembershipRepositoryInterface;
use TowerDNS\Application\Services\AccountInvitationRegistrationService;
use TowerDNS\Application\Services\AccountInvitationService;
use TowerDNS\Application\Services\AuthorizationService;
use TowerDNS\Application\Services\PasswordPolicy;
use TowerDNS\Application\Services\PermissionService;
use TowerDNS\Application\Services\ResourceLimitService;
use TowerDNS\Application\Services\UserLifecycleService;
use TowerDNS\Domain\Account\AccountInvitation;
use TowerDNS\Domain\Account\TeamRole;
use TowerDNS\Infrastructure\Clock\SystemClock;
use TowerDNS\Infrastructure\Persistence\DbalAccountInvitationRepository;
use TowerDNS\Infrastructure\Persistence\DbalAccountRepository;
use TowerDNS\Infrastructure\Persistence\DbalAccountResourceLimitsRepository;
use TowerDNS\Infrastructure\Persistence\DbalManagedZoneRepository;
use TowerDNS\Infrastructure\Persistence\DbalProviderAccountRepository;
use TowerDNS\Infrastructure\Persistence\DbalTransactionRunner;
use TowerDNS\Infrastructure\Persistence\DbalUserRepository;
use TowerDNS\Infrastructure\Persistence\SchemaManager;
use TowerDNS\Infrastructure\Persistence\SqliteConnectionConfigurator;

final class AccountInvitationRegistrationServiceTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        new SqliteConnectionConfigurator()->configure($this->connection);
        new SchemaManager($this->connection)->createTablesIfNotExist();
    }

    public function testNewInviteeGetsPersonalAccountAndMembershipAtomically(): void
    {
        $clock      = new SystemClock();
        $users      = new DbalUserRepository($this->connection, $clock);
        $accounts   = new DbalAccountRepository($this->connection);
        $limits     = new DbalAccountResourceLimitsRepository($this->connection);
        $zones      = new DbalManagedZoneRepository($this->connection);
        $providers  = new DbalProviderAccountRepository($this->connection);
        $runner     = new DbalTransactionRunner($this->connection);
        $lifecycle  = new UserLifecycleService($runner, $users, $accounts, $limits, $zones, $providers);
        $ownerId    = 'b9622622-2000-4000-8000-000000000001';
        $ownerEmail = 'owner@example.test';
        $invitee    = 'new-user@example.test';

        $lifecycle->create($ownerId, $ownerEmail, password_hash('owner-password', PASSWORD_ARGON2ID));
        $organizationId = $accounts->create('Example Hosting', 'example-hosting', $ownerId, '2026-09-19 12:00:00');
        $rawToken       = str_repeat('b', 64);
        $invitationRepo = new DbalAccountInvitationRepository($this->connection);
        $invitationId   = $invitationRepo->create(
            $organizationId,
            $invitee,
            null,
            TeamRole::DNS_MANAGER,
            $ownerId,
            hash('sha256', $rawToken),
            '2026-09-19 12:00:00',
            '2999-01-01 00:00:00',
        );
        $invitation = $invitationRepo->findById($invitationId);
        self::assertInstanceOf(AccountInvitation::class, $invitation);

        $resourceLimits    = new ResourceLimitService($limits, $zones, $accounts, $providers);
        $permissions       = new PermissionService($accounts, $this->createMock(ZoneMembershipRepositoryInterface::class), new AuthorizationService(new \TowerDNS\Application\Services\RbacPermissionChecker()));
        $invitationService = new AccountInvitationService(
            $invitationRepo,
            $accounts,
            $users,
            $permissions,
            $resourceLimits,
            new \TowerDNS\Application\Services\MailService('null://null', 'noreply@example.test', 'TowerDNS'),
        );
        $registration = new AccountInvitationRegistrationService(
            $invitationService,
            $invitationRepo,
            $accounts,
            $users,
            $lifecycle,
            $resourceLimits,
            $runner,
            new PasswordPolicy(8),
        );

        $result = $registration->register($rawToken, $invitee, 'a-secure-invited-password');

        self::assertSame($organizationId, $result->organizationAccountId);
        self::assertNotSame($ownerId, $result->userId);
        self::assertSame($result->userId, $this->connection->fetchOne('SELECT id FROM users WHERE email = ?', [$invitee]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM accounts WHERE personal_user_id = ?', [$result->userId]));
        self::assertSame('dns_manager', $this->connection->fetchOne('SELECT role FROM account_memberships WHERE account_id = ? AND user_id = ?', [$organizationId, $result->userId]));
        self::assertNotNull($this->connection->fetchOne('SELECT accepted_at FROM account_invitations WHERE id = ?', [$invitationId]));
        $storedHash = (string) $this->connection->fetchOne('SELECT password_hash FROM users WHERE id = ?', [$result->userId]);
        self::assertNotSame('a-secure-invited-password', $storedHash);
        self::assertTrue(password_verify('a-secure-invited-password', $storedHash));

        $this->expectException(\DomainException::class);
        $registration->register($rawToken, $invitee, 'a-secure-invited-password');
    }
}

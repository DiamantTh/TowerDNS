<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Installation;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use TowerDNS\Infrastructure\Installation\FreshInstallBootstrapper;
use TowerDNS\Infrastructure\Installation\FreshInstallBootstrapRequest;

final class FreshInstallBootstrapperTest extends TestCase
{
    public function testBootstrapsAnEmptyDatabaseToAUsableAdminAndOwnerAccount(): void
    {
        $connection   = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $bootstrapper = new FreshInstallBootstrapper($connection);
        $request      = $this->request();

        $result = $bootstrapper->bootstrap($request);

        self::assertTrue($result->createdInitialState);
        self::assertSame($request->adminId, $result->adminId);
        self::assertTrue(password_verify('CorrectHorseBatteryStaple!', (string) $connection->fetchOne(
            'SELECT password_hash FROM users WHERE id = ?',
            [$request->adminId],
        )));
        self::assertSame('en-GB', $connection->fetchOne('SELECT locale FROM users WHERE id = ?', [$request->adminId]));
        self::assertSame('superadmin', $connection->fetchOne(
            'SELECT role_id FROM user_roles WHERE user_id = ?',
            [$request->adminId],
        ));
        self::assertSame($request->adminId, $connection->fetchOne('SELECT owner_user_id FROM accounts WHERE slug = ?', [$request->accountSlug]));
        self::assertSame('owner', $connection->fetchOne(
            'SELECT role FROM account_memberships am JOIN accounts a ON a.id = am.account_id WHERE am.user_id = ? AND a.slug = ?',
            [$request->adminId, 'personal-' . $request->adminId],
        ));
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM account_resource_limits'));
        self::assertNull($connection->fetchOne('SELECT max_zones FROM account_resource_limits'));
        self::assertGreaterThanOrEqual(6, (int) $connection->fetchOne('SELECT COUNT(*) FROM roles WHERE is_system = 1'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM managed_zones'));
    }

    public function testRetryDoesNotReplaceTheFirstAdminOrDamageTheBootstrapState(): void
    {
        $connection   = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $bootstrapper = new FreshInstallBootstrapper($connection);
        $first        = $this->request();
        $bootstrapper->bootstrap($first);

        $retry = new FreshInstallBootstrapRequest(
            '6a1df001-3000-4000-9000-000000000002',
            'other@example.test',
            password_hash('DifferentCorrectHorseBatteryStaple!', PASSWORD_ARGON2ID),
            'Other Admin',
            'Other account',
            'other-account',
            '2026-09-16 12:01:00',
        );
        $result = $bootstrapper->bootstrap($retry);

        self::assertFalse($result->createdInitialState);
        self::assertSame($first->adminId, $result->adminId);
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM users'));
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM accounts'));
        self::assertSame($first->adminEmail, $connection->fetchOne('SELECT email FROM users'));
    }

    public function testRepairsOnlyTheSafeInterruptedStateWithOneExistingUserAndNoAccount(): void
    {
        $connection   = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $bootstrapper = new FreshInstallBootstrapper($connection);
        $request      = $this->request();
        $bootstrapper->bootstrap($request);
        $connection->executeStatement('DELETE FROM account_resource_limits');
        $connection->executeStatement('DELETE FROM account_memberships');
        $connection->executeStatement('DELETE FROM accounts');

        $result = $bootstrapper->bootstrap($request);

        self::assertTrue($result->createdInitialState);
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM accounts'));
        self::assertSame($request->adminId, $connection->fetchOne('SELECT owner_user_id FROM accounts WHERE slug = ?', ['personal-' . $request->adminId]));
    }

    public function testRejectsAnAmbiguousIncompleteDatabaseWithoutChangingIt(): void
    {
        $connection   = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $bootstrapper = new FreshInstallBootstrapper($connection);
        $request      = $this->request();
        $bootstrapper->bootstrap($request);
        $connection->executeStatement('DELETE FROM account_resource_limits');
        $connection->executeStatement('DELETE FROM account_memberships');
        $connection->executeStatement('DELETE FROM accounts');
        $connection->insert('users', [
            'id'                    => '6a1df001-3000-4000-9000-000000000003',
            'email'                 => 'second@example.test',
            'display_name'          => 'Second',
            'password_hash'         => password_hash('SecondCorrectHorseBatteryStaple!', PASSWORD_ARGON2ID),
            'totp_secret_encrypted' => null,
            'active'                => true,
            'theme'                 => 'system',
            'locale'                => 'en',
            'last_login_at'         => null,
            'created_at'            => '2026-09-16 12:02:00',
            'updated_at'            => '2026-09-16 12:02:00',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot determine an owner');
        $bootstrapper->bootstrap($request);
    }

    public function testRejectsAReservedPersonalSlugForTheNamedAccount(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $request    = $this->request();
        $invalid    = new FreshInstallBootstrapRequest(
            $request->adminId,
            $request->adminEmail,
            $request->adminPasswordHash,
            $request->adminDisplayName,
            $request->accountName,
            'personal-' . $request->adminId,
            $request->createdAt,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('slug namespace is reserved');
        new FreshInstallBootstrapper($connection)->bootstrap($invalid);
    }

    private function request(): FreshInstallBootstrapRequest
    {
        return new FreshInstallBootstrapRequest(
            '6a1df001-3000-4000-9000-000000000001',
            'admin@example.test',
            password_hash('CorrectHorseBatteryStaple!', PASSWORD_ARGON2ID),
            'Administrator',
            'TowerDNS',
            'towerdns',
            '2026-09-16 12:00:00',
        );
    }
}

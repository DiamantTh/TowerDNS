<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Persistence;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use TowerDNS\Infrastructure\Clock\SystemClock;
use TowerDNS\Infrastructure\Persistence\DbalUserRepository;
use TowerDNS\Infrastructure\Persistence\SchemaManager;

final class DbalUserRepositoryTest extends TestCase
{
    public function testAdministrativeBulkLookupLoadsInactiveUsersAndRoles(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $schema     = new SchemaManager($connection);
        $schema->createTablesIfNotExist();
        $schema->seedSystemRoles();

        $connection->insert('users', [
            'id'                    => '6c74d6ca-2d12-41df-a913-f146bc4785ea',
            'email'                 => 'inactive@example.test',
            'password_hash'         => password_hash('not-used', PASSWORD_ARGON2ID),
            'totp_secret_encrypted' => null,
            'active'                => false,
            'theme'                 => 'system',
            'language'              => 'en-GB',
            'locale'                => 'en-GB',
            'timezone'              => 'UTC',
            'created_at'            => '2026-09-19 00:00:00',
            'updated_at'            => '2026-09-19 00:00:00',
        ]);
        $connection->insert('user_roles', [
            'user_id' => '6c74d6ca-2d12-41df-a913-f146bc4785ea',
            'role_id' => 'viewer',
        ]);

        $repository = new DbalUserRepository($connection, new SystemClock());
        $users      = $repository->findByIdsForAdministration([
            '6c74d6ca-2d12-41df-a913-f146bc4785ea',
            'missing-user',
        ]);

        self::assertCount(1, $users);
        self::assertFalse($users['6c74d6ca-2d12-41df-a913-f146bc4785ea']->active);
        self::assertSame('viewer', $users['6c74d6ca-2d12-41df-a913-f146bc4785ea']->roles[0]->id);
    }
}

<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Persistence;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use TowerDNS\Application\Exception\TotpCredentialLimitException;
use TowerDNS\Infrastructure\Clock\SystemClock;
use TowerDNS\Infrastructure\Persistence\DbalTotpCredentialRepository;
use TowerDNS\Infrastructure\Persistence\SchemaManager;

/** @psalm-api Runtime discovery by PHPUnit or local module loading is not statically visible. */
final class DbalTotpCredentialRepositoryTest extends TestCase
{
    public function testStoresListsUsesAndDeletesOwnedCredentialsIndependently(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $schema     = new SchemaManager($connection);
        $schema->createTablesIfNotExist();
        $schema->seedSystemRoles();
        $schema->seedFirstUser('11111111-1111-4111-8111-111111111111', 'totp@example.test', 'password-hash');
        $repository = new DbalTotpCredentialRepository($connection, new SystemClock());

        $repository->save('credential-1', '11111111-1111-4111-8111-111111111111', 'encrypted-1', 'Phone', 5);
        $repository->save('credential-2', '11111111-1111-4111-8111-111111111111', 'encrypted-2', 'Tablet', 5);
        $repository->markUsed('credential-2', '11111111-1111-4111-8111-111111111111');

        self::assertSame(2, $repository->countByUserId('11111111-1111-4111-8111-111111111111'));
        self::assertSame('encrypted-1', $repository->findByUserId('11111111-1111-4111-8111-111111111111')[0]['secret_encrypted']);
        self::assertNotNull($repository->findByUserId('11111111-1111-4111-8111-111111111111')[1]['last_used_at']);

        $repository->delete('credential-1', 'someone-else');
        self::assertSame(2, $repository->countByUserId('11111111-1111-4111-8111-111111111111'));
        $repository->delete('credential-1', '11111111-1111-4111-8111-111111111111');
        self::assertSame(1, $repository->countByUserId('11111111-1111-4111-8111-111111111111'));
    }

    public function testEnforcesConfiguredLimitWithoutRemovingExistingCredentials(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $schema     = new SchemaManager($connection);
        $schema->createTablesIfNotExist();
        $schema->seedSystemRoles();
        $userId = '22222222-2222-4222-8222-222222222222';
        $schema->seedFirstUser($userId, 'limited@example.test', 'password-hash');
        $repository = new DbalTotpCredentialRepository($connection, new SystemClock());
        $repository->save('credential-1', $userId, 'encrypted-1', 'Phone', 1);

        try {
            $repository->save('credential-2', $userId, 'encrypted-2', 'Tablet', 1);
            self::fail('Enrollment above the configured limit must fail.');
        } catch (TotpCredentialLimitException) {
            self::assertSame(1, $repository->countByUserId($userId));
        }
    }
}

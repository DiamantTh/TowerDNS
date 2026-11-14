<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use TowerDNS\Infrastructure\Persistence\DbalProviderAccountRepository;

/**
 * @psalm-api Runtime discovery by PHPUnit or local module loading is not statically visible.
 * @psalm-suppress PropertyNotSetInConstructor PHPUnit initializes fixture state in setUp().
 */
final class DbalProviderAccountRepositoryTest extends TestCase
{
    private Connection $connection;
    private DbalProviderAccountRepository $repository;

    #[\Override]
    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('CREATE TABLE provider_accounts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            account_id INTEGER NOT NULL,
            provider_type VARCHAR(64) NOT NULL,
            name VARCHAR(255) NOT NULL,
            credentials_encrypted TEXT NOT NULL,
            credentials_version INTEGER NOT NULL,
            is_active BOOLEAN NOT NULL,
            created_at TEXT NOT NULL,
            last_tested_at TEXT NULL,
            last_success_at TEXT NULL,
            last_error_category VARCHAR(64) NULL,
            last_used_at TEXT NULL,
            creation_request_id VARCHAR(64) NULL,
            UNIQUE(account_id, creation_request_id)
        )');
        $this->repository = new DbalProviderAccountRepository($this->connection);
    }

    public function testSuccessfulAndFailedChecksPreserveOnlySafeStatusAndLastSuccess(): void
    {
        $id = $this->repository->create(7, 'cloudflare', 'Production', 'encrypted-blob', 1, '2026-11-14 12:00:00', '2026-11-14 12:00:00', str_repeat('b', 64));
        $this->repository->recordVerification($id, 7, '2026-11-14 12:05:00', 'authentication_failed');

        $failed = $this->repository->findById($id);
        self::assertNotNull($failed);
        self::assertSame('2026-11-14 12:05:00', $failed->lastTestedAt);
        self::assertSame('2026-11-14 12:00:00', $failed->lastSuccessAt);
        self::assertSame('authentication_failed', $failed->lastErrorCategory);
        self::assertSame($id, $this->repository->findByCreationRequestId(7, str_repeat('b', 64))?->id);

        $this->repository->recordVerification($id, 7, '2026-11-14 12:10:00', null);
        $success = $this->repository->findById($id);
        self::assertNotNull($success);
        self::assertSame('2026-11-14 12:10:00', $success->lastTestedAt);
        self::assertSame('2026-11-14 12:10:00', $success->lastSuccessAt);
        self::assertNull($success->lastErrorCategory);
    }

    public function testCreateRetryKeyIsScopedToItsAccount(): void
    {
        $id = $this->repository->create(7, 'cloudflare', 'Private', 'cipher', 1, '2026-11-14 12:00:00', '2026-11-14 12:00:00', str_repeat('c', 64));

        self::assertSame($id, $this->repository->findByCreationRequestId(7, str_repeat('c', 64))?->id);
        self::assertNull($this->repository->findByCreationRequestId(8, str_repeat('c', 64)));
    }
}

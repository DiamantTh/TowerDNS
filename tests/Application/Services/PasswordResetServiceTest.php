<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Application\Services;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use TowerDNS\Application\Exception\PasswordResetException;
use TowerDNS\Application\Repository\UserRepositoryInterface;
use TowerDNS\Application\Services\NullBreachedPasswordChecker;
use TowerDNS\Application\Services\PasswordPolicy;
use TowerDNS\Application\Services\PasswordResetService;
use TowerDNS\Domain\Auth\PasswordResetMethod;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Infrastructure\Persistence\DbalPasswordResetTokenRepository;

final class PasswordResetServiceTest extends TestCase
{
    private Connection $connection;
    private DbalPasswordResetTokenRepository $tokens;
    private ClockInterface $clock;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('CREATE TABLE password_reset_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id VARCHAR(64) NOT NULL, token_hash VARCHAR(64) NOT NULL UNIQUE, created_at VARCHAR(19) NOT NULL, expires_at VARCHAR(19) NOT NULL, used_at VARCHAR(19) DEFAULT NULL, method VARCHAR(32) NOT NULL DEFAULT \'email_link\')');
        $this->tokens = new DbalPasswordResetTokenRepository($this->connection);
        $this->clock  = new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-09-15 12:00:00');
            }
        };
    }

    public function testItConsumesAnEmailTokenAndUpdatesThePassword(): void
    {
        $this->tokens->create('user-1', hash('sha256', 'raw-token'), '2026-09-15 13:00:00');
        $users = $this->userRepository();
        $users->expects(self::once())->method('updatePasswordHash')->with('user-1', self::isType('string'));

        $userId = $this->service($users)->consumeEmailLink('raw-token', 'correct horse battery staple');

        self::assertSame('user-1', $userId);
        $token = $this->tokens->findByHash(hash('sha256', 'raw-token'));
        self::assertNotNull($token);
        self::assertTrue($token->isUsed());
    }

    public function testItRejectsAnAlreadyConsumedToken(): void
    {
        $this->tokens->create('user-1', hash('sha256', 'raw-token'), '2026-09-15 13:00:00');
        self::assertTrue($this->tokens->consumeIfValid(1, '2026-09-15 12:00:00'));

        $this->expectException(PasswordResetException::class);
        $this->service($this->userRepository())->consumeEmailLink('raw-token', 'correct horse battery staple');
    }

    public function testEmailLinkConsumptionRejectsTokensForAnotherMethod(): void
    {
        $this->tokens->create(
            'user-1',
            hash('sha256', 'raw-token'),
            '2026-09-15 13:00:00',
            PasswordResetMethod::TEMPORARY_CODE,
        );

        $this->expectException(PasswordResetException::class);
        $this->service($this->userRepository())->consumeEmailLink('raw-token', 'correct horse battery staple');
    }

    public function testOnlyOneConcurrentConsumptionCanSucceed(): void
    {
        $this->tokens->create('user-1', hash('sha256', 'raw-token'), '2026-09-15 13:00:00');
        $service = $this->service($this->userRepository());

        $service->consumeEmailLink('raw-token', 'correct horse battery staple');

        $this->expectException(PasswordResetException::class);
        $service->consumeEmailLink('raw-token', 'correct horse battery staple');
    }

    public function testPasswordWriteFailureRollsBackTokenConsumption(): void
    {
        $this->tokens->create('user-1', hash('sha256', 'raw-token'), '2026-09-15 13:00:00');
        $users = $this->userRepository();
        $users->method('updatePasswordHash')->willThrowException(new \RuntimeException('storage unavailable'));

        $this->expectException(\RuntimeException::class);
        try {
            $this->service($users)->consumeEmailLink('raw-token', 'correct horse battery staple');
        } finally {
            $token = $this->tokens->findByHash(hash('sha256', 'raw-token'));
            self::assertNotNull($token);
            self::assertFalse($token->isUsed());
        }
    }

    private function service(UserRepositoryInterface $users): PasswordResetService
    {
        return new PasswordResetService(
            $this->connection,
            $this->tokens,
            $users,
            new PasswordPolicy(8, 0, new NullBreachedPasswordChecker()),
            $this->clock,
        );
    }

    /** @return MockObject&UserRepositoryInterface */
    private function userRepository(): UserRepositoryInterface
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findById')->willReturn(new User('user-1', 'user@example.test'));
        return $users;
    }
}

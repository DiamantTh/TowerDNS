<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Services;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use TowerDNS\Application\Exception\PasswordResetException;
use TowerDNS\Application\Repository\PasswordResetTokenRepositoryInterface;
use TowerDNS\Application\Repository\UserRepositoryInterface;
use TowerDNS\Domain\Auth\PasswordResetMethod;

/**
 * Consumes password-reset tokens exactly once together with the password
 * update. Both writes use the same database transaction.
 */
final readonly class PasswordResetService
{
    public function __construct(
        private Connection                          $connection,
        private PasswordResetTokenRepositoryInterface $tokens,
        private UserRepositoryInterface              $users,
        private PasswordPolicy                       $policy,
        private ClockInterface                       $clock,
    ) {}

    /**
     * @throws \InvalidArgumentException when the configured password policy rejects the value
     * @throws PasswordResetException when the reset token cannot be consumed
     */
    public function consumeEmailLink(string $rawToken, string $password): string
    {
        $this->policy->assertValid($password);
        $tokenHash = hash('sha256', $rawToken);
        $now       = $this->clock->now()->format('Y-m-d H:i:s');

        try {
            return $this->connection->transactional(function () use ($tokenHash, $password, $now): string {
                $token = $this->tokens->findByHash($tokenHash);
                if (!$token instanceof \TowerDNS\Domain\Auth\PasswordResetToken || !$token->isValid() || $token->method !== PasswordResetMethod::EMAIL_LINK) {
                    throw new PasswordResetException('Password reset token is invalid, expired, or already consumed.');
                }

                if (!$this->users->findById($token->userId) instanceof \TowerDNS\Domain\Auth\User) {
                    throw new PasswordResetException('Password reset token target is unavailable.');
                }

                if (!$this->tokens->consumeIfValid($token->id, $now)) {
                    throw new PasswordResetException('Password reset token was consumed concurrently.');
                }

                $hash = password_hash($password, PASSWORD_ARGON2ID);
                $this->users->updatePasswordHash($token->userId, $hash);
                return $token->userId;
            });
        } finally {
            // Do not retain a plaintext password in this service after return or failure.
            $password = '';
        }
    }
}

<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Services;

use Doctrine\DBAL\Connection;
use TowerDNS\Application\Exception\PasswordResetException;
use TowerDNS\Application\Repository\UserRepositoryInterface;

/** Shared application path for privileged password changes. */
final readonly class PasswordAdministrationService
{
    public function __construct(
        private Connection              $connection,
        private UserRepositoryInterface $users,
        private PasswordPolicy          $policy,
    ) {}

    /**
     * @return int number of invalidated API keys
     * @throws \InvalidArgumentException when the password does not meet policy
     * @throws PasswordResetException when the active target user is unavailable
     */
    public function setByAdministrator(string $targetUserId, string $password, bool $keepApiKeys): int
    {
        $this->policy->assertValid($password);

        try {
            return $this->connection->transactional(function () use ($targetUserId, $password, $keepApiKeys): int {
                if (!$this->users->findById($targetUserId) instanceof \TowerDNS\Domain\Auth\User) {
                    throw new PasswordResetException('Password reset target user is unavailable.');
                }

                $hash = password_hash($password, PASSWORD_ARGON2ID);
                $this->users->updatePasswordHash($targetUserId, $hash);
                return $keepApiKeys ? 0 : $this->users->invalidateApiKeys($targetUserId);
            });
        } finally {
            $password = '';
        }
    }
}

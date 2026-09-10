<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use TowerDNS\Application\Repository\PasswordResetTokenRepositoryInterface;
use TowerDNS\Domain\Auth\PasswordResetToken;

final readonly class DbalPasswordResetTokenRepository implements PasswordResetTokenRepositoryInterface
{
    public function __construct(private Connection $connection) {}

    public function create(string $userId, string $tokenHash, string $expiresAt): void
    {
        $this->connection->insert('password_reset_tokens', [
            'user_id'    => $userId,
            'token_hash' => $tokenHash,
            'created_at' => new \DateTimeImmutable()->format('Y-m-d H:i:s'),
            'expires_at' => $expiresAt,
            'used_at'    => null,
        ]);
    }

    public function findByHash(string $tokenHash): ?PasswordResetToken
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM password_reset_tokens WHERE token_hash = ?',
            [$tokenHash]
        );

        return $row !== false ? $this->hydrate($row) : null;
    }

    public function markUsed(int $id, string $usedAt): void
    {
        $this->connection->update(
            'password_reset_tokens',
            ['used_at' => $usedAt],
            ['id' => $id]
        );
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): PasswordResetToken
    {
        return new PasswordResetToken(
            id: (int) $row['id'],
            userId: (string) $row['user_id'],
            tokenHash: (string) $row['token_hash'],
            createdAt: (string) $row['created_at'],
            expiresAt: (string) $row['expires_at'],
            usedAt: isset($row['used_at']) ? (string) $row['used_at'] : null,
        );
    }
}

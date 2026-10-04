<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use TowerDNS\Application\Exception\TotpCredentialLimitException;
use TowerDNS\Application\Repository\TotpCredentialRepositoryInterface;

final readonly class DbalTotpCredentialRepository implements TotpCredentialRepositoryInterface
{
    public function __construct(private Connection $connection, private ClockInterface $clock) {}

    #[\Override]
    public function findByUserId(string $userId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, label, created_at, last_used_at, secret_encrypted FROM totp_credentials WHERE user_id = ? ORDER BY created_at ASC, id ASC',
            [$userId],
        );

        return array_map(static fn(array $row): array => [
            'id'               => (string) $row['id'],
            'label'            => (string) $row['label'],
            'created_at'       => (string) $row['created_at'],
            'last_used_at'     => isset($row['last_used_at']) ? (string) $row['last_used_at'] : null,
            'secret_encrypted' => (string) $row['secret_encrypted'],
        ], $rows);
    }

    #[\Override]
    public function countByUserId(string $userId): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM totp_credentials WHERE user_id = ?', [$userId]);
    }

    #[\Override]
    public function save(string $id, string $userId, string $encryptedSecret, string $label, int $maxCredentials): void
    {
        $this->connection->transactional(function (Connection $connection) use ($id, $userId, $encryptedSecret, $label, $maxCredentials): void {
            if (!PlatformDetector::isSqlite($connection)) {
                $connection->fetchOne('SELECT id FROM users WHERE id = ? FOR UPDATE', [$userId]);
            }
            $count = (int) $connection->fetchOne('SELECT COUNT(*) FROM totp_credentials WHERE user_id = ?', [$userId]);
            if ($count >= max(1, min(100, $maxCredentials))) {
                throw new TotpCredentialLimitException('The configured TOTP credential limit has been reached.');
            }
            $connection->insert('totp_credentials', [
                'id'               => $id,
                'user_id'          => $userId,
                'secret_encrypted' => $encryptedSecret,
                'label'            => $label,
                'created_at'       => $this->clock->now()->format('Y-m-d H:i:s'),
                'last_used_at'     => null,
            ]);
        });
    }

    #[\Override]
    public function markUsed(string $id, string $userId): void
    {
        $this->connection->update(
            'totp_credentials',
            ['last_used_at' => $this->clock->now()->format('Y-m-d H:i:s')],
            ['id' => $id, 'user_id' => $userId],
        );
    }

    #[\Override]
    public function delete(string $id, string $userId): void
    {
        $this->connection->delete('totp_credentials', ['id' => $id, 'user_id' => $userId]);
    }
}

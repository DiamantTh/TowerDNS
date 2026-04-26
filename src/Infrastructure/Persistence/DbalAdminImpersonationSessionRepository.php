<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use TowerDNS\Application\Repository\AdminImpersonationSessionRepositoryInterface;
use TowerDNS\Domain\Account\AdminImpersonationSession;

final class DbalAdminImpersonationSessionRepository implements AdminImpersonationSessionRepositoryInterface
{
    public function __construct(private readonly Connection $connection) {}

    public function findById(string $id): ?AdminImpersonationSession
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM admin_impersonation_sessions WHERE id = ?',
            [$id]
        );
        return $row !== false ? $this->hydrate($row) : null;
    }

    public function findActiveForActor(string $actorUserId): ?AdminImpersonationSession
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM admin_impersonation_sessions
             WHERE actor_user_id = ?
               AND ended_at IS NULL
               AND expires_at > ?
             ORDER BY created_at DESC
             LIMIT 1',
            [$actorUserId, $now]
        );
        return $row !== false ? $this->hydrate($row) : null;
    }

    public function create(
        string $id,
        string $actorUserId,
        ?string $effectiveUserId,
        ?int   $effectiveAccountId,
        string $reason,
        string $createdAt,
        string $expiresAt,
    ): void {
        $this->connection->insert('admin_impersonation_sessions', [
            'id'                   => $id,
            'actor_user_id'        => $actorUserId,
            'effective_user_id'    => $effectiveUserId,
            'effective_account_id' => $effectiveAccountId,
            'reason'               => $reason,
            'created_at'           => $createdAt,
            'expires_at'           => $expiresAt,
            'ended_at'             => null,
        ]);
    }

    public function end(string $id, string $endedAt): void
    {
        $this->connection->update(
            'admin_impersonation_sessions',
            ['ended_at' => $endedAt],
            ['id'       => $id]
        );
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): AdminImpersonationSession
    {
        return new AdminImpersonationSession(
            id: (string) $row['id'],
            actorUserId: (string) $row['actor_user_id'],
            effectiveUserId: isset($row['effective_user_id']) ? (string) $row['effective_user_id'] : null,
            effectiveAccountId: isset($row['effective_account_id']) ? (int) $row['effective_account_id'] : null,
            reason: (string) ($row['reason'] ?? ''),
            createdAt: (string) ($row['created_at'] ?? ''),
            expiresAt: (string) ($row['expires_at'] ?? ''),
            endedAt: isset($row['ended_at']) ? (string) $row['ended_at'] : null,
        );
    }
}

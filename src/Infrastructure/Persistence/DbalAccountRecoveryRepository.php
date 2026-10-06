<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use TowerDNS\Application\Repository\AccountRecoveryRepositoryInterface;
use TowerDNS\Domain\Auth\PasswordResetMethod;

final readonly class DbalAccountRecoveryRepository implements AccountRecoveryRepositoryInterface
{
    public function __construct(private Connection $connection) {}

    #[\Override]
    public function revokeOpenForUser(string $userId, string $at): int
    {
        $this->connection->executeStatement(
            'UPDATE password_reset_tokens SET used_at = ? WHERE user_id = ? AND method = ? AND used_at IS NULL',
            [$at, $userId, PasswordResetMethod::RECOVERY_CODE->value],
        );
        return (int) $this->connection->executeStatement(
            "UPDATE account_recoveries SET status = 'revoked', session_id_hash = NULL, session_expires_at = NULL WHERE user_id = ? AND status IN ('authorized', 'in_progress')",
            [$userId],
        );
    }

    #[\Override]
    public function openForUser(string $userId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT id, status, authorized_by FROM account_recoveries WHERE user_id = ? AND status IN ('authorized', 'in_progress')",
            [$userId],
        );
        return array_map(static fn(array $row): array => [
            'id'            => (string) $row['id'],
            'status'        => (string) $row['status'],
            'authorized_by' => isset($row['authorized_by']) ? (string) $row['authorized_by'] : null,
        ], $rows);
    }

    #[\Override]
    public function invalidateNormalSessions(string $userId): void
    {
        $this->connection->executeStatement('UPDATE users SET auth_session_version = auth_session_version + 1 WHERE id = ?', [$userId]);
    }

    #[\Override]
    public function create(string $id, string $userId, string $authorizedBy, int $ticketId, string $createdAt, string $expiresAt): void
    {
        $this->connection->insert('account_recoveries', [
            'id'                 => $id,
            'user_id'            => $userId,
            'authorized_by'      => $authorizedBy,
            'ticket_id'          => $ticketId,
            'status'             => 'authorized',
            'created_at'         => $createdAt,
            'expires_at'         => $expiresAt,
            'redeemed_at'        => null,
            'session_id_hash'    => null,
            'session_expires_at' => null,
            'completed_at'       => null,
        ]);
    }

    #[\Override]
    public function addCredentialSnapshot(string $recoveryId, string $type, string $credentialIdHash, bool $preRecovery, string $createdAt): void
    {
        $this->connection->insert('account_recovery_credentials', [
            'recovery_id'        => $recoveryId,
            'credential_type'    => $type,
            'credential_id_hash' => $credentialIdHash,
            'pre_recovery'       => $preRecovery ? 1 : 0,
            'created_at'         => $createdAt,
        ]);
    }

    #[\Override]
    public function findByTicketId(int $ticketId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, user_id, authorized_by, ticket_id, status, expires_at FROM account_recoveries WHERE ticket_id = ?',
            [$ticketId],
        );
        return $row === false ? null : [
            'id'            => (string) $row['id'],
            'user_id'       => (string) $row['user_id'],
            'authorized_by' => isset($row['authorized_by']) ? (string) $row['authorized_by'] : null,
            'ticket_id'     => (int) $row['ticket_id'],
            'status'        => (string) $row['status'],
            'expires_at'    => (string) $row['expires_at'],
        ];
    }

    #[\Override]
    public function begin(string $id, string $userId, int $ticketId, string $sessionIdHash, string $redeemedAt, string $sessionExpiresAt): bool
    {
        return $this->connection->executeStatement(
            "UPDATE account_recoveries SET status = 'in_progress', redeemed_at = ?, session_id_hash = ?, session_expires_at = ? WHERE id = ? AND user_id = ? AND ticket_id = ? AND status = 'authorized' AND expires_at > ?",
            [$redeemedAt, $sessionIdHash, $sessionExpiresAt, $id, $userId, $ticketId, $redeemedAt],
        ) === 1;
    }

    #[\Override]
    public function findForSession(string $id, string $userId, string $sessionIdHash): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, user_id, authorized_by, status, expires_at, session_expires_at FROM account_recoveries WHERE id = ? AND user_id = ? AND session_id_hash = ?',
            [$id, $userId, $sessionIdHash],
        );
        return $row === false ? null : [
            'id'                 => (string) $row['id'],
            'user_id'            => (string) $row['user_id'],
            'authorized_by'      => isset($row['authorized_by']) ? (string) $row['authorized_by'] : null,
            'status'             => (string) $row['status'],
            'expires_at'         => (string) $row['expires_at'],
            'session_expires_at' => isset($row['session_expires_at']) ? (string) $row['session_expires_at'] : null,
        ];
    }

    #[\Override]
    public function lockForSession(string $id, string $userId, string $sessionIdHash, string $now): bool
    {
        // The no-op update takes a row write lock on PostgreSQL/MySQL and the
        // transaction write lock on SQLite, serializing mutation with expiry.
        $this->connection->executeStatement(
            "UPDATE account_recoveries SET status = status WHERE id = ? AND user_id = ? AND session_id_hash = ? AND status = 'in_progress' AND expires_at > ? AND session_expires_at > ?",
            [$id, $userId, $sessionIdHash, $now, $now],
        );
        return (bool) $this->connection->fetchOne(
            "SELECT 1 FROM account_recoveries WHERE id = ? AND user_id = ? AND session_id_hash = ? AND status = 'in_progress' AND expires_at > ? AND session_expires_at > ?",
            [$id, $userId, $sessionIdHash, $now, $now],
        );
    }

    #[\Override]
    public function credentials(string $recoveryId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT credential_type, credential_id_hash, pre_recovery FROM account_recovery_credentials WHERE recovery_id = ?',
            [$recoveryId],
        );
        return array_map(static fn(array $row): array => [
            'type'               => (string) $row['credential_type'],
            'credential_id_hash' => (string) $row['credential_id_hash'],
            'pre_recovery'       => (bool) $row['pre_recovery'],
        ], $rows);
    }

    #[\Override]
    public function recordNewWebAuthn(string $recoveryId, string $credentialIdHash, string $at): void
    {
        $this->connection->insert('account_recovery_credentials', [
            'recovery_id'        => $recoveryId,
            'credential_type'    => 'webauthn',
            'credential_id_hash' => $credentialIdHash,
            'pre_recovery'       => 0,
            'created_at'         => $at,
        ]);
    }

    #[\Override]
    public function complete(string $recoveryId, string $at): bool
    {
        return $this->connection->executeStatement(
            "UPDATE account_recoveries SET status = 'completed', completed_at = ?, session_id_hash = NULL, session_expires_at = NULL WHERE id = ? AND status = 'in_progress'",
            [$at, $recoveryId],
        ) === 1;
    }

    #[\Override]
    public function abort(string $recoveryId, string $status, string $at): bool
    {
        if (!in_array($status, ['aborted', 'expired'], true)) {
            throw new \InvalidArgumentException('Unsupported recovery terminal status.');
        }
        $changed = $this->connection->executeStatement(
            'UPDATE account_recoveries SET status = ?, session_id_hash = NULL, session_expires_at = NULL WHERE id = ? AND status IN (\'authorized\', \'in_progress\')',
            [$status, $recoveryId],
        ) === 1;
        if ($changed) {
            $ticketId = $this->connection->fetchOne('SELECT ticket_id FROM account_recoveries WHERE id = ?', [$recoveryId]);
            if (is_numeric($ticketId)) {
                $this->connection->executeStatement('UPDATE password_reset_tokens SET used_at = ? WHERE id = ? AND used_at IS NULL', [$at, (int) $ticketId]);
            }
        }
        return $changed;
    }

    #[\Override]
    public function isLocked(string $userId, string $now): bool
    {
        return (bool) $this->connection->fetchOne(
            "SELECT 1 FROM account_recoveries WHERE user_id = ? AND ((status = 'authorized' AND expires_at > ?) OR (status = 'in_progress' AND expires_at > ? AND session_expires_at > ?)) LIMIT 1",
            [$userId, $now, $now, $now],
        );
    }

    #[\Override]
    public function expiredForUser(string $userId, string $now): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT id, authorized_by, status, expires_at, session_expires_at FROM account_recoveries WHERE user_id = ? AND ((status = 'authorized' AND expires_at <= ?) OR (status = 'in_progress' AND (expires_at <= ? OR session_expires_at <= ?)))",
            [$userId, $now, $now, $now],
        );
        return array_map(static fn(array $row): array => [
            'id'                 => (string) $row['id'],
            'authorized_by'      => isset($row['authorized_by']) ? (string) $row['authorized_by'] : null,
            'status'             => (string) $row['status'],
            'expires_at'         => (string) $row['expires_at'],
            'session_expires_at' => isset($row['session_expires_at']) ? (string) $row['session_expires_at'] : null,
        ], $rows);
    }
}

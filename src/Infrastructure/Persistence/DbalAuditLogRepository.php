<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use TowerDNS\Application\Repository\AuditLogRepositoryInterface;
use TowerDNS\Domain\Account\AuditLogEntry;

/**
 * Append-only audit log repository.
 * Rows are never updated or deleted.
 */
final readonly class DbalAuditLogRepository implements AuditLogRepositoryInterface
{
    public function __construct(private Connection $connection) {}

    #[\Override]
    public function append(AuditLogEntry $entry, string $createdAt): void
    {
        $this->connection->insert('audit_logs', [
            'actor_user_id'            => $entry->actorUserId,
            'effective_user_id'        => $entry->effectiveUserId,
            'account_id'               => $entry->accountId,
            'zone_id'                  => $entry->zoneId,
            'provider_account_id'      => $entry->providerAccountId,
            'impersonation_session_id' => $entry->impersonationSessionId,
            'action'                   => $entry->action,
            'target_type'              => $entry->targetType,
            'target_id'                => $entry->targetId,
            'before_json'              => $entry->beforeJson   !== null ? json_encode($entry->beforeJson) : null,
            'after_json'               => $entry->afterJson    !== null ? json_encode($entry->afterJson) : null,
            'metadata_json'            => $entry->metadataJson !== null ? json_encode($entry->metadataJson) : null,
            'ip_address'               => $entry->ipAddress,
            'user_agent'               => $entry->userAgent,
            'created_at'               => $createdAt,
        ]);
    }

    #[\Override]
    public function pageForAccount(int $accountId, ?string $zoneId, array $filters, int $offset): array
    {
        $where  = ['account_id = ?'];
        $params = [$accountId];
        if ($zoneId !== null) {
            $where[]  = 'zone_id = ?';
            $params[] = $zoneId;
        }
        foreach (['action' => 'action', 'target' => 'target_type', 'provider' => 'provider_account_id', 'since' => 'created_at'] as $filter => $column) {
            if (isset($filters[$filter])) {
                $where[]  = $column . ($filter === 'since' ? ' >= ?' : ' = ?');
                $params[] = $filters[$filter];
            }
        }
        return $this->connection->fetchAllAssociative(
            'SELECT id, action, target_type, target_id, account_id, zone_id, provider_account_id,
                    actor_user_id, effective_user_id, impersonation_session_id, created_at
             FROM audit_logs WHERE ' . implode(' AND ', $where) . '
             ORDER BY created_at DESC, id DESC LIMIT 51 OFFSET ?',
            [...$params, max(0, $offset)],
            [...array_fill(0, count($params), \Doctrine\DBAL\ParameterType::STRING), \Doctrine\DBAL\ParameterType::INTEGER],
        );
    }

    #[\Override]
    public function findByAccount(int $accountId, int $limit = 100, int $offset = 0): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT * FROM audit_logs WHERE account_id = ?
             ORDER BY created_at DESC LIMIT ? OFFSET ?',
            [$accountId, $limit, $offset]
        );
    }

    #[\Override]
    public function findByZone(string $zoneId, int $limit = 50, int $offset = 0): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT * FROM audit_logs WHERE zone_id = ?
             ORDER BY created_at DESC LIMIT ? OFFSET ?',
            [$zoneId, $limit, $offset]
        );
    }

    #[\Override]
    public function findByImpersonationSession(string $sessionId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT * FROM audit_logs WHERE impersonation_session_id = ?
             ORDER BY created_at ASC',
            [$sessionId]
        );
    }
}

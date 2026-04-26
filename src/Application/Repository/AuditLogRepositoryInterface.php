<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Repository;

use TowerDNS\Domain\Account\AuditLogEntry;

interface AuditLogRepositoryInterface
{
    /**
     * Appends an audit log entry. The log is append-only — no update or delete.
     *
     * IMPORTANT: The entry MUST NOT contain secrets. Callers are responsible
     * for scrubbing before_json / after_json / metadata_json.
     */
    public function append(AuditLogEntry $entry, string $createdAt): void;

    /**
     * Returns the most recent N entries for an account (descending order).
     * @return list<array<string, mixed>>
     */
    public function findByAccount(int $accountId, int $limit = 100, int $offset = 0): array;

    /**
     * Returns the most recent N entries for a zone.
     * @return list<array<string, mixed>>
     */
    public function findByZone(string $zoneId, int $limit = 50, int $offset = 0): array;

    /**
     * Returns all entries for a specific admin impersonation session.
     * @return list<array<string, mixed>>
     */
    public function findByImpersonationSession(string $sessionId): array;
}

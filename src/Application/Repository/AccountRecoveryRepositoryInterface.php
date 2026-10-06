<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Repository;

interface AccountRecoveryRepositoryInterface
{
    public function revokeOpenForUser(string $userId, string $at): int;

    /** @return list<array{id:string,status:string,authorized_by:?string}> */
    public function openForUser(string $userId): array;

    public function invalidateNormalSessions(string $userId): void;

    public function create(string $id, string $userId, string $authorizedBy, int $ticketId, string $createdAt, string $expiresAt): void;

    public function addCredentialSnapshot(string $recoveryId, string $type, string $credentialIdHash, bool $preRecovery, string $createdAt): void;

    /** @return array{id:string,user_id:string,authorized_by:?string,ticket_id:int,status:string,expires_at:string}|null */
    public function findByTicketId(int $ticketId): ?array;

    public function begin(string $id, string $userId, int $ticketId, string $sessionIdHash, string $redeemedAt, string $sessionExpiresAt): bool;

    /** @return array{id:string,user_id:string,authorized_by:?string,status:string,expires_at:string,session_expires_at:?string}|null */
    public function findForSession(string $id, string $userId, string $sessionIdHash): ?array;

    /** Acquire the active recovery row for a state-changing operation. */
    public function lockForSession(string $id, string $userId, string $sessionIdHash, string $now): bool;

    /** @return list<array{type:string,credential_id_hash:string,pre_recovery:bool}> */
    public function credentials(string $recoveryId): array;

    public function recordNewWebAuthn(string $recoveryId, string $credentialIdHash, string $at): void;

    public function complete(string $recoveryId, string $at): bool;

    public function abort(string $recoveryId, string $status, string $at): bool;

    public function isLocked(string $userId, string $now): bool;

    /** @return list<array{id:string,authorized_by:?string,status:string,expires_at:string,session_expires_at:?string}> */
    public function expiredForUser(string $userId, string $now): array;
}

<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Domain\Account;

/**
 * An active admin-impersonation session (Admin-Switch).
 *
 * actor_user_id   = real system admin who initiated the switch
 * effective_*     = the user/account context being acted on behalf of
 *
 * All actions taken while an impersonation session is active MUST be
 * written to the audit log with both actor_user_id and effective_user_id set.
 */
final readonly class AdminImpersonationSession
{
    public function __construct(
        public string  $id,
        public string  $actorUserId,
        public ?string $effectiveUserId = null,
        public ?int    $effectiveAccountId = null,
        public string  $reason = '',
        public string  $createdAt = '',
        public string  $expiresAt = '',
        public ?string $endedAt = null,
    ) {}

    public function isExpired(): bool
    {
        return $this->endedAt !== null || (
            $this->expiresAt !== '' && $this->expiresAt < (new \DateTimeImmutable())->format('Y-m-d H:i:s')
        );
    }
}

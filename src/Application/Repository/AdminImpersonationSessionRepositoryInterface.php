<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Repository;

use TowerDNS\Domain\Account\AdminImpersonationSession;

interface AdminImpersonationSessionRepositoryInterface
{
    public function findById(string $id): ?AdminImpersonationSession;

    /**
     * Returns the currently active session for an actor, or null if none active.
     */
    public function findActiveForActor(string $actorUserId): ?AdminImpersonationSession;

    public function create(
        string $id,
        string $actorUserId,
        ?string $effectiveUserId,
        ?int   $effectiveAccountId,
        string $reason,
        string $createdAt,
        string $expiresAt,
    ): void;

    public function end(string $id, string $endedAt): void;
}

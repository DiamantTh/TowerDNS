<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Services;

use TowerDNS\Application\DTO\AuditContext;
use TowerDNS\Domain\Auth\User;

interface AccountRecoveryIssuerInterface
{
    /** @return array{recovery_id:string,raw_ticket:string,expires_at:string} */
    public function authorize(User $target, string $actorId, AuditContext $context): array;
}

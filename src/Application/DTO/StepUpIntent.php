<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\DTO;

/** Server-side intent binding the second-factor challenge to one mutation. */
final readonly class StepUpIntent
{
    public function __construct(
        public string $actorUserId,
        public string $action,
        public string $targetId,
        public ?string $impersonationSessionId,
        public int $startedAt,
    ) {}
}

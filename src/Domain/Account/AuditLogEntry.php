<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Domain\Account;

/**
 * Immutable audit log entry.
 *
 * IMPORTANT: This object MUST NEVER contain secrets (API keys, tokens,
 * passwords, Authorization headers, or provider credential values).
 */
final readonly class AuditLogEntry
{
    public function __construct(
        public ?string $actorUserId,
        public string  $action,
        public string  $targetType,
        public ?string $targetId = null,
        public ?string $effectiveUserId = null,
        public ?int    $accountId = null,
        public ?string $zoneId = null,
        public ?int    $providerAccountId = null,
        public ?string $impersonationSessionId = null,
        /** @var array<string, mixed>|null */
        public ?array  $beforeJson = null,
        /** @var array<string, mixed>|null */
        public ?array  $afterJson = null,
        /** @var array<string, mixed>|null */
        public ?array  $metadataJson = null,
        public ?string $ipAddress = null,
        public ?string $userAgent = null,
    ) {}
}

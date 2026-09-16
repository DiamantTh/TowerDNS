<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\DTO;

/**
 * Transport-neutral provenance for an auditable application mutation.
 *
 * HTTP adapters may add IP and user-agent data; CLI and future API callers
 * can provide the same actor/effective identity without manufacturing a
 * ServerRequestInterface.
 */
final readonly class AuditContext
{
    public function __construct(
        public ?string $actorUserId,
        public ?string $effectiveUserId = null,
        public ?int $accountId = null,
        public ?string $zoneId = null,
        public ?int $providerAccountId = null,
        public ?string $impersonationSessionId = null,
        public ?string $ipAddress = null,
        public ?string $userAgent = null,
    ) {}

    public function forManagedZone(int $accountId, int $managedZoneId): self
    {
        return new self(
            $this->actorUserId,
            $this->effectiveUserId,
            $accountId,
            (string) $managedZoneId,
            $this->providerAccountId,
            $this->impersonationSessionId,
            $this->ipAddress,
            $this->userAgent,
        );
    }
}

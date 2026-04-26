<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Domain\Account;

/**
 * Team roles for account- and zone-level membership.
 *
 * These are distinct from the system-level {@see \TowerDNS\Domain\Auth\Permission}
 * enum, which controls panel-wide admin functions (IAM, system settings, etc.).
 * TeamRole governs what a member may do within the context of a specific
 * Account or Zone.
 */
enum TeamRole: string
{
    /** Full control over the account including billing, deletion and ownership transfer. */
    case OWNER = 'owner';

    /** DNS management + member management (cannot remove owners). */
    case ADMIN = 'admin';

    /** May create, update and delete DNS records on permitted zones. */
    case DNS_MANAGER = 'dns_manager';

    /** Read-only access to zones and records. */
    case VIEWER = 'viewer';

    /** Read-only access to audit logs and configuration. Cannot mutate anything. */
    case AUDITOR = 'auditor';

    // ── Capability checks ─────────────────────────────────────────────────────

    public function canManageAccount(): bool
    {
        return match ($this) {
            self::OWNER, self::ADMIN => true,
            default => false,
        };
    }

    public function canManageMembers(): bool
    {
        return match ($this) {
            self::OWNER, self::ADMIN => true,
            default => false,
        };
    }

    public function canManageProviderAccounts(): bool
    {
        return match ($this) {
            self::OWNER, self::ADMIN => true,
            default => false,
        };
    }

    public function canManageZoneRecords(): bool
    {
        return match ($this) {
            self::OWNER, self::ADMIN, self::DNS_MANAGER => true,
            default => false,
        };
    }

    public function canViewZone(): bool
    {
        return match ($this) {
            self::OWNER, self::ADMIN, self::DNS_MANAGER, self::VIEWER, self::AUDITOR => true,
        };
    }

    public function canViewAuditLog(): bool
    {
        return match ($this) {
            self::OWNER, self::ADMIN, self::AUDITOR => true,
            default => false,
        };
    }

    public function canDeleteAccount(): bool
    {
        return $this === self::OWNER;
    }

    public function canTransferOwnership(): bool
    {
        return $this === self::OWNER;
    }
}

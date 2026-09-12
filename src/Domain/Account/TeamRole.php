<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Domain\Account;

use TowerDNS\Domain\Auth\Permission;
use TowerDNS\Domain\Auth\Role;

/**
 * Team roles for account- and zone-level membership.
 *
 * These are distinct from the system-level {@see Permission}
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

    /**
     * The flat technical permission bundle granted by this account/zone role.
     *
     * Memberships provide the scope. This enum intentionally does not encode
     * scope rules or provider capabilities.
     *
     * @return list<Permission>
     */
    public function permissions(): array
    {
        $viewer = [
            Permission::ACCOUNT_READ,
            Permission::ZONE_LIST,
            Permission::ZONE_READ,
            Permission::RECORD_READ,
            Permission::DNSSEC_STATUS_READ,
        ];

        return match ($this) {
            self::OWNER => [
                ...$viewer,
                Permission::ACCOUNT_UPDATE,
                Permission::ACCOUNT_DELETE,
                Permission::ACCOUNT_OWNERSHIP_TRANSFER,
                Permission::ACCOUNT_MEMBERS_MANAGE,
                Permission::PROVIDER_CREDENTIALS_MANAGE,
                Permission::ZONE_CREATE,
                Permission::ZONE_UPDATE,
                Permission::ZONE_DELETE,
                Permission::RECORD_CREATE,
                Permission::RECORD_UPDATE,
                Permission::RECORD_DELETE,
                Permission::DNSSEC_ACTION_EXECUTE,
                Permission::AUDIT_READ,
            ],
            self::ADMIN => [
                ...$viewer,
                Permission::ACCOUNT_UPDATE,
                Permission::ACCOUNT_MEMBERS_MANAGE,
                Permission::PROVIDER_CREDENTIALS_MANAGE,
                Permission::ZONE_CREATE,
                Permission::ZONE_UPDATE,
                Permission::ZONE_DELETE,
                Permission::RECORD_CREATE,
                Permission::RECORD_UPDATE,
                Permission::RECORD_DELETE,
                Permission::DNSSEC_ACTION_EXECUTE,
                Permission::AUDIT_READ,
            ],
            self::DNS_MANAGER => [
                ...$viewer,
                Permission::RECORD_CREATE,
                Permission::RECORD_UPDATE,
                Permission::RECORD_DELETE,
            ],
            self::VIEWER  => $viewer,
            self::AUDITOR => [...$viewer, Permission::AUDIT_READ],
        };
    }

    /**
     * Exposes the bundle as a Laminas RBAC role without adding a second role
     * hierarchy or persistence model for account memberships.
     */
    public function asRole(): Role
    {
        return new Role(
            id: 'team.' . $this->value,
            name: $this->value,
            permissions: $this->permissions(),
            isSystem: true,
        );
    }

    /** @deprecated Use PermissionService::authorizeAccount() with Permission::ACCOUNT_UPDATE. */
    public function canManageAccount(): bool
    {
        return in_array(Permission::ACCOUNT_UPDATE, $this->permissions(), true);
    }

    /** @deprecated Use PermissionService::authorizeAccount() with Permission::ACCOUNT_MEMBERS_MANAGE. */
    public function canManageMembers(): bool
    {
        return in_array(Permission::ACCOUNT_MEMBERS_MANAGE, $this->permissions(), true);
    }

    /** @deprecated Use PermissionService::authorizeAccount() with Permission::PROVIDER_CREDENTIALS_MANAGE. */
    public function canManageProviderAccounts(): bool
    {
        return in_array(Permission::PROVIDER_CREDENTIALS_MANAGE, $this->permissions(), true);
    }

    /** @deprecated Use PermissionService::authorizeZone() with record permissions. */
    public function canManageZoneRecords(): bool
    {
        return in_array(Permission::RECORD_UPDATE, $this->permissions(), true);
    }

    /** @deprecated Use PermissionService::authorizeZone() with Permission::ZONE_READ. */
    public function canViewZone(): bool
    {
        return in_array(Permission::ZONE_READ, $this->permissions(), true);
    }

    /** @deprecated Use PermissionService::authorizeAccount() with Permission::AUDIT_READ. */
    public function canViewAuditLog(): bool
    {
        return in_array(Permission::AUDIT_READ, $this->permissions(), true);
    }

    /** @deprecated Use PermissionService::authorizeAccount() with Permission::ACCOUNT_DELETE. */
    public function canDeleteAccount(): bool
    {
        return in_array(Permission::ACCOUNT_DELETE, $this->permissions(), true);
    }

    /** @deprecated Use PermissionService::authorizeAccount() with Permission::ACCOUNT_OWNERSHIP_TRANSFER. */
    public function canTransferOwnership(): bool
    {
        return in_array(Permission::ACCOUNT_OWNERSHIP_TRANSFER, $this->permissions(), true);
    }
}

<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Domain\Auth;

/** Central catalogue for Core and future local-module permission definitions. */
final class PermissionRegistry
{
    /** @var array<string, PermissionDefinition> */
    private array $definitions = [];

    public function __construct()
    {
        foreach ($this->coreDefinitions() as $definition) {
            $this->register($definition);
        }
    }

    public function register(PermissionDefinition $definition): void
    {
        $id = self::normalize($definition->id);
        if (isset($this->definitions[$id])) {
            throw new \LogicException(sprintf('Permission-ID-Kollision: %s', $definition->id));
        }
        $this->definitions[$id] = new PermissionDefinition($id, $definition->label, $definition->description, $definition->scopeKinds);
    }

    public function has(string|Permission $permission): bool
    {
        return isset($this->definitions[self::normalize($permission instanceof Permission ? $permission->value : $permission)]);
    }

    public function assertKnown(string|Permission $permission): string
    {
        $id = self::normalize($permission instanceof Permission ? $permission->value : $permission);
        if (!isset($this->definitions[$id])) {
            throw new \InvalidArgumentException(sprintf('Unbekannte Permission-ID: %s', $id));
        }
        return $id;
    }

    /** @return list<PermissionDefinition> */
    public function all(): array
    {
        return array_values($this->definitions);
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_keys($this->definitions);
    }

    public static function normalize(string $id): string
    {
        $id = strtolower(trim($id));
        if (preg_match('/^[a-z][a-z0-9]*(?:\.[a-z][a-z0-9-]*)+$/D', $id) !== 1) {
            throw new \InvalidArgumentException('Permission-ID muss klein geschrieben und punkt-namespaced sein.');
        }
        return $id;
    }

    /** @return list<PermissionDefinition> */
    private function coreDefinitions(): array
    {
        return [
            new PermissionDefinition(Permission::ACCOUNT_READ->value, 'permission.account.read.label', 'permission.account.read.description', ['account']),
            new PermissionDefinition(Permission::ACCOUNT_UPDATE->value, 'permission.account.update.label', 'permission.account.update.description', ['account']),
            new PermissionDefinition(Permission::ACCOUNT_DELETE->value, 'permission.account.delete.label', 'permission.account.delete.description', ['account']),
            new PermissionDefinition(Permission::ACCOUNT_OWNERSHIP_TRANSFER->value, 'permission.account.ownership.transfer.label', 'permission.account.ownership.transfer.description', ['account']),
            new PermissionDefinition(Permission::ACCOUNT_MEMBERS_MANAGE->value, 'permission.account.members.manage.label', 'permission.account.members.manage.description', ['account']),
            new PermissionDefinition(Permission::ZONE_LIST->value, 'permission.zone.list.label', 'permission.zone.list.description', ['account']),
            new PermissionDefinition(Permission::ZONE_READ->value, 'permission.zone.read.label', 'permission.zone.read.description', ['account', 'zone']),
            new PermissionDefinition(Permission::ZONE_CREATE->value, 'permission.zone.create.label', 'permission.zone.create.description', ['account']),
            new PermissionDefinition(Permission::ZONE_UPDATE->value, 'permission.zone.update.label', 'permission.zone.update.description', ['account']),
            new PermissionDefinition(Permission::ZONE_DELETE->value, 'permission.zone.delete.label', 'permission.zone.delete.description', ['account']),
            new PermissionDefinition(Permission::RECORD_READ->value, 'permission.record.read.label', 'permission.record.read.description', ['account', 'zone']),
            new PermissionDefinition(Permission::RECORD_CREATE->value, 'permission.record.create.label', 'permission.record.create.description', ['account', 'zone']),
            new PermissionDefinition(Permission::RECORD_UPDATE->value, 'permission.record.update.label', 'permission.record.update.description', ['account', 'zone']),
            new PermissionDefinition(Permission::RECORD_DELETE->value, 'permission.record.delete.label', 'permission.record.delete.description', ['account', 'zone']),
            new PermissionDefinition(Permission::DNSSEC_STATUS_READ->value, 'permission.dnssec.status.read.label', 'permission.dnssec.status.read.description', ['account', 'zone']),
            new PermissionDefinition(Permission::DNSSEC_ACTION_EXECUTE->value, 'permission.dnssec.action.execute.label', 'permission.dnssec.action.execute.description', ['account', 'zone']),
            new PermissionDefinition(Permission::PROVIDER_CREDENTIALS_MANAGE->value, 'permission.provider.credentials.manage.label', 'permission.provider.credentials.manage.description', ['account']),
            new PermissionDefinition(Permission::PROVIDER_CONFIG_MANAGE->value, 'permission.provider.config.manage.label', 'permission.provider.config.manage.description', ['system']),
            new PermissionDefinition(Permission::AUDIT_READ->value, 'permission.audit.read.label', 'permission.audit.read.description', ['account']),
            new PermissionDefinition(Permission::USER_MANAGE->value, 'permission.user.manage.label', 'permission.user.manage.description', ['system']),
            new PermissionDefinition(Permission::ROLE_MANAGE->value, 'permission.role.manage.label', 'permission.role.manage.description', ['system']),
            new PermissionDefinition(Permission::SYSTEM_SETTINGS_MANAGE->value, 'permission.system.settings.manage.label', 'permission.system.settings.manage.description', ['system']),
            new PermissionDefinition(Permission::SYSTEM_ACCOUNTS_ACCESS->value, 'permission.system.accounts.access.label', 'permission.system.accounts.access.description', ['system']),
            new PermissionDefinition(Permission::SYSTEM_IMPERSONATION_EXECUTE->value, 'permission.system.impersonation.execute.label', 'permission.system.impersonation.execute.description', ['system']),
        ];
    }
}

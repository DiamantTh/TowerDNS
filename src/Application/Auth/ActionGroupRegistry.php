<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Auth;

use TowerDNS\Domain\Auth\Permission;
use TowerDNS\Domain\Auth\PermissionRegistry;

/** Catalogue of UI action groups over registered technical permissions. */
final class ActionGroupRegistry
{
    /** @var array<string, ActionGroupDefinition> */
    private array $definitions = [];

    public function __construct(private readonly PermissionRegistry $permissions)
    {
        foreach ($this->coreDefinitions() as $definition) {
            $this->register($definition);
        }
    }

    public function register(ActionGroupDefinition $definition): void
    {
        $id = self::normalize($definition->id);
        if (isset($this->definitions[$id])) {
            throw new \LogicException(sprintf('Action-Group-ID-Kollision: %s', $definition->id));
        }
        if ($definition->permissionIds === []) {
            throw new \InvalidArgumentException('Eine Action Group benötigt mindestens eine Permission.');
        }

        $permissionIds = [];
        foreach ($definition->permissionIds as $permissionId) {
            $permissionIds[] = $this->permissions->assertKnown($permissionId);
        }

        $this->definitions[$id] = new ActionGroupDefinition(
            $id,
            $definition->label,
            $definition->description,
            array_values(array_unique($permissionIds)),
        );
    }

    /** @return list<ActionGroupDefinition> */
    public function all(): array
    {
        return array_values($this->definitions);
    }

    public static function normalize(string $id): string
    {
        $id = strtolower(trim($id));
        if (preg_match('/^[a-z][a-z0-9]*(?:\.[a-z][a-z0-9-]*)+$/D', $id) !== 1) {
            throw new \InvalidArgumentException('Action-Group-ID muss klein geschrieben und punkt-namespaced sein.');
        }

        return $id;
    }

    /** @return list<ActionGroupDefinition> */
    private function coreDefinitions(): array
    {
        return [
            new ActionGroupDefinition('dns.zones.view', 'action-group.dns.zones.view.label', 'action-group.dns.zones.view.description', [Permission::ZONE_LIST->value, Permission::ZONE_READ->value]),
            new ActionGroupDefinition('dns.zones.manage', 'action-group.dns.zones.manage.label', 'action-group.dns.zones.manage.description', [Permission::ZONE_LIST->value, Permission::ZONE_READ->value, Permission::ZONE_CREATE->value, Permission::ZONE_UPDATE->value]),
            new ActionGroupDefinition('dns.records.view', 'action-group.dns.records.view.label', 'action-group.dns.records.view.description', [Permission::RECORD_READ->value]),
            new ActionGroupDefinition('dns.records.manage', 'action-group.dns.records.manage.label', 'action-group.dns.records.manage.description', [Permission::RECORD_READ->value, Permission::RECORD_CREATE->value, Permission::RECORD_UPDATE->value, Permission::RECORD_DELETE->value]),
            new ActionGroupDefinition('dns.dnssec.view', 'action-group.dns.dnssec.view.label', 'action-group.dns.dnssec.view.description', [Permission::DNSSEC_STATUS_READ->value]),
            new ActionGroupDefinition('dns.dnssec.manage', 'action-group.dns.dnssec.manage.label', 'action-group.dns.dnssec.manage.description', [Permission::DNSSEC_STATUS_READ->value, Permission::DNSSEC_ACTION_EXECUTE->value]),
            new ActionGroupDefinition('providers.manage', 'action-group.providers.manage.label', 'action-group.providers.manage.description', [Permission::PROVIDER_CREDENTIALS_MANAGE->value, Permission::PROVIDER_CONFIG_MANAGE->value]),
            new ActionGroupDefinition('audit.view', 'action-group.audit.view.label', 'action-group.audit.view.description', [Permission::AUDIT_READ->value]),
        ];
    }
}

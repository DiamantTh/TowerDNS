<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Application\Auth;

use PHPUnit\Framework\TestCase;
use TowerDNS\Application\Auth\ActionGroupDefinition;
use TowerDNS\Application\Auth\ActionGroupRegistry;
use TowerDNS\Domain\Auth\Permission;
use TowerDNS\Domain\Auth\PermissionRegistry;

final class ActionGroupRegistryTest extends TestCase
{
    public function testDnsRecordManagementExpandsToTechnicalPermissions(): void
    {
        $registry = new ActionGroupRegistry(new PermissionRegistry());
        $groups   = array_column($registry->all(), null, 'id');

        self::assertSame([
            Permission::RECORD_READ->value,
            Permission::RECORD_CREATE->value,
            Permission::RECORD_UPDATE->value,
            Permission::RECORD_DELETE->value,
        ], $groups['dns.records.manage']->permissionIds);
    }

    public function testProviderActionGroupsDoNotMixAccountAndSystemScopes(): void
    {
        $groups = array_column(new ActionGroupRegistry(new PermissionRegistry())->all(), null, 'id');

        self::assertSame([Permission::PROVIDER_CREDENTIALS_MANAGE->value], $groups['providers.accounts.manage']->permissionIds);
        self::assertSame([Permission::PROVIDER_CONFIG_MANAGE->value], $groups['providers.system.manage']->permissionIds);
        self::assertArrayNotHasKey('providers.manage', $groups);
    }

    public function testRejectsUnknownPermissionAndCaseCollidingGroupId(): void
    {
        $registry = new ActionGroupRegistry(new PermissionRegistry());

        $this->expectException(\InvalidArgumentException::class);
        $registry->register(new ActionGroupDefinition('towerdns.tlsa.manage', 'action-group.tlsa.manage.label', null, ['towerdns.tlsa.manage']));
    }

    public function testRejectsCaseCollidingActionGroupIds(): void
    {
        $registry = new ActionGroupRegistry(new PermissionRegistry());
        $registry->register(new ActionGroupDefinition('towerdns.tlsa.manage', 'action-group.tlsa.manage.label', null, [Permission::RECORD_READ->value]));

        $this->expectException(\LogicException::class);
        $registry->register(new ActionGroupDefinition('TowerDNS.TLSA.manage', 'action-group.tlsa.manage.label', null, [Permission::RECORD_READ->value]));
    }
}

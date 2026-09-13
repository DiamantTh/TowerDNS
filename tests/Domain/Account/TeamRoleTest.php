<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Domain\Account;

use PHPUnit\Framework\TestCase;
use TowerDNS\Domain\Account\TeamRole;
use TowerDNS\Domain\Auth\Permission;

final class TeamRoleTest extends TestCase
{
    public function testMembershipRoleIsNotMarkedAsBuiltIn(): void
    {
        $role = TeamRole::DNS_MANAGER->asRole();

        self::assertFalse($role->isBuiltIn);
        self::assertTrue($role->has(Permission::RECORD_UPDATE));
    }

    public function testMembershipRoleKeepsItsTechnicalPermissionIds(): void
    {
        $role = TeamRole::DNS_MANAGER->asRole();

        self::assertSame([
            'account.read',
            'zone.list',
            'zone.read',
            'record.read',
            'dnssec.status.read',
            'record.create',
            'record.update',
            'record.delete',
        ], $role->getPermissionIds());
    }
}

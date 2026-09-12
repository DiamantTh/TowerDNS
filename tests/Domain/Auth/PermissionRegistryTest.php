<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Domain\Auth;

use PHPUnit\Framework\TestCase;
use TowerDNS\Application\Services\AuthorizationService;
use TowerDNS\Domain\Auth\PermissionDefinition;
use TowerDNS\Domain\Auth\PermissionRegistry;
use TowerDNS\Domain\Auth\Role;
use TowerDNS\Domain\Auth\User;

final class PermissionRegistryTest extends TestCase
{
    public function testModulePermissionIsNormalisedAndPreservedByRole(): void
    {
        $registry = new PermissionRegistry();
        $registry->register(new PermissionDefinition('TowerDNS.TLSA.manage', 'TLSA verwalten'));
        $role = new Role('tlsa-manager', 'TLSA Manager', ['towerdns.tlsa.manage']);

        self::assertSame(['towerdns.tlsa.manage'], $role->getPermissionIds());
        self::assertTrue(new AuthorizationService(permissions: $registry)->isGranted(
            new User('u1', 'u@example.test', [$role]),
            'towerdns.tlsa.manage',
        ));
    }

    public function testCaseInsensitivePermissionCollisionIsRejected(): void
    {
        $registry = new PermissionRegistry();
        $registry->register(new PermissionDefinition('towerdns.tlsa.read', 'TLSA ansehen'));

        $this->expectException(\LogicException::class);
        $registry->register(new PermissionDefinition('TowerDNS.TLSA.read', 'TLSA Read'));
    }

    public function testUnknownPermissionIsRejectedBeforeAuthorization(): void
    {
        $registry = new PermissionRegistry();

        $this->expectException(\InvalidArgumentException::class);
        new AuthorizationService(permissions: $registry)->isGranted(
            new User('u1', 'u@example.test', [new Role('r1', 'Role', ['towerdns.unknown.read'])]),
            'towerdns.unknown.read',
        );
    }
}

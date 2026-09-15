<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Domain\Auth;

use PHPUnit\Framework\TestCase;
use TowerDNS\Application\Services\AuthorizationService;
use TowerDNS\Domain\Auth\Permission;
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

    public function testCorePermissionsHaveTranslationKeysAndPermittedScopes(): void
    {
        $definitions = new PermissionRegistry()->all();

        self::assertCount(count(Permission::cases()), $definitions);
        foreach ($definitions as $definition) {
            self::assertStringStartsWith('permission.', $definition->label);
            self::assertStringStartsWith('permission.', (string) $definition->description);
            self::assertNotEmpty($definition->scopeKinds);
        }
    }

    public function testRejectsUnknownScopeKind(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new \ReflectionClass(PermissionDefinition::class)->newInstanceArgs([
            'towerdns.tlsa.read',
            'permission.tlsa.read.label',
            null,
            ['module'],
        ]);
    }

    public function testBuiltInSuperadminReceivesOnlyCurrentlyRegisteredModulePermissions(): void
    {
        $superadmin = new User('root', 'root@example.test', [
            new Role('superadmin', 'Super Administrator', isBuiltIn: true),
        ]);
        $normalRole = new User('manager', 'manager@example.test', [
            new Role('tlsa-manager', 'TLSA Manager', ['towerdns.tlsa.manage']),
        ]);

        $active = new PermissionRegistry();
        $active->register(new PermissionDefinition('towerdns.tlsa.manage', 'permission.tlsa.manage.label', null, ['account', 'zone']));
        $authorization = new AuthorizationService(permissions: $active);

        self::assertTrue($authorization->isGranted($superadmin, 'towerdns.tlsa.manage'));
        self::assertTrue($authorization->isGranted($normalRole, 'towerdns.tlsa.manage'));

        $inactive = new AuthorizationService(permissions: new PermissionRegistry());
        self::expectException(\InvalidArgumentException::class);
        $inactive->isGranted($superadmin, 'towerdns.tlsa.manage');
    }

    public function testReactivatingAModuleRestoresItsPersistedGrantWithoutGrantingItToOtherRoles(): void
    {
        $registry = new PermissionRegistry();
        $registry->register(new PermissionDefinition('towerdns.tlsa.read', 'permission.tlsa.read.label', null, ['zone']));
        $authorization = new AuthorizationService(permissions: $registry);

        $granted    = new User('u1', 'u1@example.test', [new Role('r1', 'TLSA reader', ['towerdns.tlsa.read'])]);
        $notGranted = new User('u2', 'u2@example.test', [new Role('r2', 'Viewer')]);

        self::assertTrue($authorization->isGranted($granted, 'towerdns.tlsa.read'));
        self::assertFalse($authorization->isGranted($notGranted, 'towerdns.tlsa.read'));
    }
}

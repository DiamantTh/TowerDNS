<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Application\Services;

use PHPUnit\Framework\TestCase;
use TowerDNS\Application\Services\RbacPermissionChecker;
use TowerDNS\Domain\Auth\Permission;
use TowerDNS\Domain\Auth\Role;

final class RbacPermissionCheckerTest extends TestCase
{
    public function testChecksDirectPermissionAndRoleHierarchyWithoutRegistryState(): void
    {
        $reader = new Role('reader', 'Reader', [Permission::RECORD_READ]);
        $editor = new Role('editor', 'Editor');
        $editor->addChild($reader);

        $checker = new RbacPermissionChecker();

        self::assertTrue($checker->isGranted($editor, Permission::RECORD_READ->value));
        self::assertFalse($checker->isGranted($editor, Permission::RECORD_DELETE->value));
        self::assertTrue($checker->isGrantedByAny([$editor], Permission::RECORD_READ->value));
    }
}

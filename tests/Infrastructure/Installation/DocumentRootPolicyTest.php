<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Installation;

use PHPUnit\Framework\TestCase;
use TowerDNS\Infrastructure\Installation\DocumentRootPolicy;

/** @psalm-suppress UnusedClass PHPUnit discovers this test class from its filename. */
final class DocumentRootPolicyTest extends TestCase
{
    public function testAcceptsOnlyTheHttpdocsDirectory(): void
    {
        $root = dirname(__DIR__, 3);

        self::assertTrue(DocumentRootPolicy::matches($root, $root . '/httpdocs'));
        self::assertTrue(DocumentRootPolicy::matches($root, $root . '/httpdocs/'));
        self::assertFalse(DocumentRootPolicy::matches($root, $root));
        self::assertFalse(DocumentRootPolicy::matches($root, null));
        self::assertFalse(DocumentRootPolicy::matches($root, sys_get_temp_dir()));
    }
}

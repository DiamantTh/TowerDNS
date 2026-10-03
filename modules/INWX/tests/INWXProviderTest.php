<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Module\INWX\Tests;

require_once dirname(__DIR__) . '/module.php';

use PHPUnit\Framework\TestCase;
use TowerDNS\Application\Contracts\Capability;
use TowerDNS\Module\INWX\INWXProvider;

/** @psalm-api Runtime discovery by PHPUnit or local module loading is not statically visible. */
final class INWXProviderTest extends TestCase
{
    public function testDoesNotAdvertiseZoneUpdatesWithoutAnUpdateOperation(): void
    {
        self::assertFalse((new INWXProvider('test-user', 'test-password'))->capabilities()->supports(Capability::ZONE_UPDATE));
    }
}

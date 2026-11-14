<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Module\INWX\Tests;

require_once dirname(__DIR__) . '/module.php';

use INWX\Domrobot;
use PHPUnit\Framework\TestCase;
use TowerDNS\Application\Contracts\Capability;
use TowerDNS\Module\INWX\INWXAPIClient;
use TowerDNS\Module\INWX\INWXProvider;

/** @psalm-api Runtime discovery by PHPUnit or local module loading is not statically visible. */
final class INWXProviderTest extends TestCase
{
    public function testDoesNotAdvertiseZoneUpdatesWithoutAnUpdateOperation(): void
    {
        self::assertFalse(new INWXProvider('test-user', 'test-password')->capabilities()->supports(Capability::ZONE_UPDATE));
    }

    public function testConnectionVerificationChecksOneReadOnlyZoneResultAfterLogin(): void
    {
        $sdk = $this->getMockBuilder(Domrobot::class)->enableOriginalConstructor()->getMock();
        $sdk->expects(self::once())->method('login')->with('test-user', 'test-password', null)
            ->willReturn(['code' => 1000, 'resData' => []]);
        $sdk->expects(self::once())->method('call')->with('nameserver', 'list', ['pagelimit' => 1, 'page' => 1])
            ->willReturn(['code' => 1000, 'resData' => []]);

        $provider = new INWXProvider('test-user', 'test-password', new INWXAPIClient('test-user', 'test-password', $sdk));
        self::assertTrue($provider->verifyConnection()->succeeded());
    }
}

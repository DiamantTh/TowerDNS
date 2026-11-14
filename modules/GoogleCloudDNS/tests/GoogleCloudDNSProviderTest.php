<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Module\GoogleCloudDNS\Tests;

require_once dirname(__DIR__) . '/module.php';

use Google\Client;
use Google\Service\Dns;
use Google\Service\Dns\Resource\ManagedZones;
use Google\Service\Exception as GoogleException;
use PHPUnit\Framework\TestCase;
use TowerDNS\Application\DTO\ProviderConnectionVerification;
use TowerDNS\Application\Exception\ProviderRequestException;
use TowerDNS\Module\GoogleCloudDNS\GoogleCloudDNSProvider;

/** @psalm-api Runtime discovery by PHPUnit or local module loading is not statically visible. */
final class GoogleCloudDNSProviderTest extends TestCase
{
    public function testConnectionVerificationUsesSingleReadOnlyPage(): void
    {
        $zones = $this->createMock(ManagedZones::class);
        $zones->expects(self::once())->method('listManagedZones')->with('project-a', ['maxResults' => 1])->willReturn(new \stdClass());
        $provider = new GoogleCloudDNSProvider($this->dns($zones), 'project-a');

        self::assertSame(ProviderConnectionVerification::SUCCESS, $provider->verifyConnection()->category);
    }

    public function testConnectionVerificationPreservesOnlyHttpStatusForClassification(): void
    {
        $zones = $this->createMock(ManagedZones::class);
        $zones->method('listManagedZones')->willThrowException(new GoogleException('sensitive provider response', 403));
        $provider = new GoogleCloudDNSProvider($this->dns($zones), 'project-a');

        try {
            $provider->verifyConnection();
            self::fail('A failed Google API request must be reported to the verifier.');
        } catch (ProviderRequestException $exception) {
            self::assertSame(403, $exception->getCode());
            self::assertStringNotContainsString('sensitive provider response', $exception->getMessage());
        }
    }

    private function dns(ManagedZones $zones): Dns
    {
        $service               = new Dns(new Client());
        $service->managedZones = $zones;
        return $service;
    }
}

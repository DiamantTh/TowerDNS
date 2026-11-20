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

    public function testConditionalChangeSendsExactDeletionAndReportsProviderRace(): void
    {
        $zones = $this->createMock(ManagedZones::class);
        $zones->method('get')->willReturn(new \Google\Service\Dns\ManagedZone(['dnsName' => 'example.org.']));
        $dns = $this->dns($zones);
        $sets = $this->createMock(\Google\Service\Dns\Resource\ResourceRecordSets::class);
        $changes = $this->createMock(\Google\Service\Dns\Resource\Changes::class);
        $dns->resourceRecordSets = $sets;
        $dns->changes = $changes;
        $a = '3 1 1 ' . str_repeat('a', 64);
        $b = '3 1 1 ' . str_repeat('b', 64);
        $old = new \Google\Service\Dns\ResourceRecordSet(['name' => '_443._tcp.example.org.', 'type' => 'TLSA', 'ttl' => 600, 'rrdatas' => [$a]]);
        $sets->expects(self::once())->method('get')->with('project-a', 'zone-a', '_443._tcp.example.org.', 'TLSA')->willReturn($old);
        $changes->expects(self::once())->method('create')->willReturnCallback(
            static function (string $project, string $zone, \Google\Service\Dns\Change $change) use ($old, $a, $b): never {
                self::assertSame('project-a', $project);
                self::assertSame('zone-a', $zone);
                self::assertSame([$old], $change->getDeletions());
                self::assertSame([$a, $b], $change->getAdditions()[0]->getRrdatas());
                throw new GoogleException('precondition failed', 412);
            },
        );
        $provider = new GoogleCloudDNSProvider($dns, 'project-a');
        $type = \TowerDNS\Domain\DNS\DNSRecordType::parse('TLSA');
        $before = new \TowerDNS\Domain\DNS\Rrset('zone-a', '_443._tcp', $type, 600, [$a]);
        $after = new \TowerDNS\Domain\DNS\Rrset('zone-a', '_443._tcp', $type, 600, [$a, $b]);
        $this->expectException(\TowerDNS\Application\Exception\RrsetConflictException::class);
        $provider->changeRrsetConditionally($before, $after);
    }

    private function dns(ManagedZones $zones): Dns
    {
        $service               = new Dns(new Client());
        $service->managedZones = $zones;
        return $service;
    }
}

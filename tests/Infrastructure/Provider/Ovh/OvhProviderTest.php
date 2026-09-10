<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Provider\Ovh;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Ovh\Api;
use PHPUnit\Framework\TestCase;
use TowerDNS\Application\Contracts\Capability;
use TowerDNS\Application\Exception\CapabilityException;
use TowerDNS\Application\Exception\ProviderRequestException;
use TowerDNS\Domain\DNS\DnssecState;
use TowerDNS\Domain\DNS\Record;
use TowerDNS\Domain\DNS\RecordType;
use TowerDNS\Infrastructure\Provider\Ovh\OvhProvider;

final class OvhProviderTest extends TestCase
{
    public function testListsZonesUsingDnsDeploymentStatusOnly(): void
    {
        $api = $this->createMock(Api::class);
        $api->expects(self::exactly(3))->method('get')->willReturnMap([
            ['/domain/zone', null, null, ['example.org', 'pending.org']],
            ['/domain/zone/example.org/status', null, null, ['isDeployed' => true]],
            ['/domain/zone/pending.org/status', null, null, ['isDeployed' => false]],
        ]);
        $zones = new OvhProvider($api)->listZones();
        self::assertCount(2, $zones);
        self::assertTrue($zones[0]->active);
        self::assertFalse($zones[1]->active);
        self::assertSame('ovh', $zones[0]->providerId);
    }

    public function testListsTlsaAndResolvesInheritedTtlWithoutChangingUnsupportedRecords(): void
    {
        $api = $this->createMock(Api::class);
        $api->expects(self::exactly(4))->method('get')->willReturnMap([
            ['/domain/zone/example.org/record', null, null, [17, 18]],
            ['/domain/zone/example.org/record/17', null, null, $this->row(ttl: null)],
            ['/domain/zone/example.org/soa', null, null, ['ttl' => 1800]],
            ['/domain/zone/example.org/record/18', null, null, ['fieldType' => 'HTTPS']],
        ]);
        $api->expects(self::never())->method('put');
        $api->expects(self::never())->method('delete');
        $records = new OvhProvider($api)->listRecords('example.org');
        self::assertCount(1, $records);
        self::assertSame('17', $records[0]->id);
        self::assertSame('_443._tcp', $records[0]->name);
        self::assertSame(RecordType::TLSA, $records[0]->type);
        self::assertSame(1800, $records[0]->ttl);
    }

    public function testCreatesTlsaThenRefreshesWithoutReplacingSiblings(): void
    {
        $api = $this->createMock(Api::class);
        $calls = [];
        $api->expects(self::exactly(2))->method('post')->willReturnCallback(
            function (string $path, ?array $payload) use (&$calls): ?array {
                $calls[] = [$path, $payload];
                return count($calls) === 1 ? $this->row() : null;
            },
        );
        $api->expects(self::never())->method('put');
        $api->expects(self::never())->method('delete');
        $record = new OvhProvider($api)->createRecord($this->record());
        self::assertSame('17', $record->id);
        self::assertSame([
            ['/domain/zone/example.org/record', ['fieldType' => 'TLSA',
                'subDomain' => '_443._tcp', 'target' => '3 1 1 deadbeef', 'ttl' => 300]],
            ['/domain/zone/example.org/refresh', null],
        ], $calls);
    }

    public function testUpdateUsesNativeIdAndReadsBackAfterPublishing(): void
    {
        $api = $this->createMock(Api::class);
        $calls = [];
        $api->expects(self::exactly(2))->method('get')->with('/domain/zone/example.org/record/17')
            ->willReturnCallback(function () use (&$calls): array {
                $calls[] = 'get';
                return $this->row();
            });
        $api->expects(self::once())->method('put')->with('/domain/zone/example.org/record/17', [
            'subDomain' => '_443._tcp', 'target' => '3 1 1 deadbeef', 'ttl' => 300,
        ])->willReturnCallback(static function () use (&$calls): void { $calls[] = 'put'; });
        $api->expects(self::once())->method('post')->with('/domain/zone/example.org/refresh', null)
            ->willReturnCallback(static function () use (&$calls): void { $calls[] = 'refresh'; });
        $api->expects(self::never())->method('delete');
        $updated = new OvhProvider($api)->updateRecord($this->record());
        self::assertSame('17', $updated->id);
        self::assertSame(['get', 'put', 'refresh', 'get'], $calls);
    }

    public function testTypeChangesAreRejectedBeforeAnyWrite(): void
    {
        $api = $this->createMock(Api::class);
        $api->method('get')->willReturn(['fieldType' => 'TXT']);
        $api->expects(self::never())->method('put');
        $api->expects(self::never())->method('post');
        $this->expectException(CapabilityException::class);
        new OvhProvider($api)->updateRecord($this->record());
    }

    public function testDeleteRefreshesZoneAndOnlyDeletesSpecifiedId(): void
    {
        $api = $this->createMock(Api::class);
        $calls = [];
        $api->expects(self::once())->method('delete')->with('/domain/zone/example.org/record/17')
            ->willReturnCallback(static function () use (&$calls): void { $calls[] = 'delete'; });
        $api->expects(self::once())->method('post')->with('/domain/zone/example.org/refresh', null)
            ->willReturnCallback(static function () use (&$calls): void { $calls[] = 'refresh'; });
        new OvhProvider($api)->deleteRecord('example.org', '17');
        self::assertSame(['delete', 'refresh'], $calls);
    }

    public function testRefreshFailureReportsSavedButUnpublishedChange(): void
    {
        $api = $this->createMock(Api::class);
        $api->method('post')->willReturnCallback(function (string $path): array {
            if (str_ends_with($path, '/refresh')) {
                throw new ConnectException('connection failed', new Request('POST', 'https://example.org'));
            }
            return $this->row();
        });
        $this->expectException(ProviderRequestException::class);
        $this->expectExceptionMessage('Änderung gespeichert');
        new OvhProvider($api)->createRecord($this->record());
    }

    public function testDnssecReadMapsPendingStateAndCapabilitiesAreConservative(): void
    {
        $api = $this->createMock(Api::class);
        $api->expects(self::once())->method('get')->with('/domain/zone/example.org/dnssec')
            ->willReturn(['status' => 'enableInProgress']);
        $provider = new OvhProvider($api);
        self::assertSame(DnssecState::PARTIAL, $provider->getDnssecProfile('example.org')->state);
        self::assertFalse($provider->capabilities()->supports(Capability::ZONE_CREATE));
        self::assertFalse($provider->capabilities()->supports(Capability::ZONE_DELETE));
        self::assertFalse($provider->capabilities()->supports(Capability::DNSSEC_ACTION_EXECUTE));
        self::assertFalse($provider->capabilities()->supports(Capability::RECORD_COMMENT));
    }

    public function testApexUsesEmptySubdomainAndPreservesTxtPresentation(): void
    {
        $api = $this->createMock(Api::class);
        $api->expects(self::exactly(2))->method('post')->willReturnCallback(
            static function (string $path, ?array $payload): ?array {
                if (str_ends_with($path, '/refresh')) {
                    return null;
                }
                self::assertIsArray($payload);
                self::assertSame('', $payload['subDomain']);
                self::assertSame('"hello" "world"', $payload['target']);
                return ['id' => 1, 'fieldType' => 'TXT', 'subDomain' => null,
                    'target' => '"hello" "world"', 'ttl' => 300];
            },
        );
        $result = new OvhProvider($api)->createRecord(new Record('', 'example.org', '@', RecordType::TXT, 300, '"hello" "world"'));
        self::assertSame('', $result->name);
        self::assertSame('"hello" "world"', $result->content);
    }

    private function record(): Record
    {
        return new Record('17', 'example.org', '_443._tcp', RecordType::TLSA, 300, '3 1 1 deadbeef');
    }

    /** @return array{id: int, fieldType: string, subDomain: string, target: string, ttl: int|null} */
    private function row(?int $ttl = 300): array
    {
        return ['id' => 17, 'fieldType' => 'TLSA', 'subDomain' => '_443._tcp', 'target' => '3 1 1 deadbeef', 'ttl' => $ttl];
    }
}

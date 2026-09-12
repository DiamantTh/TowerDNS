<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Module\ClouDNS\Tests;

require_once dirname(__DIR__) . '/module.php';

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use TowerDNS\Application\Contracts\Capability;
use TowerDNS\Application\Exception\CapabilityException;
use TowerDNS\Application\Exception\ProviderRequestException;
use TowerDNS\Domain\DNS\Record;
use TowerDNS\Domain\DNS\RecordType;
use TowerDNS\Module\ClouDNS\ClouDNSAPIClient;
use TowerDNS\Module\ClouDNS\ClouDNSProvider;

final class ClouDNSProviderTest extends TestCase
{
    /** @var array<int, array<int|string, mixed>> */
    private array $history = [];

    /** @param list<array<int|string, mixed>> $responses */
    private function provider(array $responses): ClouDNSProvider
    {
        $stack         = HandlerStack::create(new MockHandler(array_map(static fn(array $body): Response => new Response(200, [], json_encode($body, JSON_THROW_ON_ERROR)), $responses)));
        $history       = [];
        $this->history = & $history;
        $stack->push(Middleware::history($history));
        return new ClouDNSProvider(new ClouDNSAPIClient('123', 'secret&=value', 'sub-auth-id', new Client(['handler' => $stack])));
    }

    /** @return array<int|string, mixed> */
    private function body(int $index): array
    {
        $history = $this->historyArray();
        parse_str((string) $history[$index]['request']->getBody(), $body);
        return $body;
    }

    /** @return array<int, array<int|string, mixed>> */
    private function historyArray(): array
    {
        return $this->history;
    }

    public function testZonePaginationIncludesSecondaryAndDoesNotLeakCredentialsIntoUrl(): void
    {
        $first = [];
        for ($i = 0; $i < 100; $i++) {
            $first[] = ['name' => "zone{$i}.example", 'type' => 'master'];
        }
        $provider = $this->provider([$first, [['name' => 'secondary.example', 'type' => 'slave']]]);
        $zones    = $provider->listZones();
        self::assertCount(101, $zones);
        self::assertSame('slave', $zones[100]->metadata['type']);
        self::assertSame('2', $this->body(1)['page']);
        self::assertSame('secret&=value', $this->body(0)['auth-password']);
        self::assertSame('', $this->historyArray()[0]['request']->getUri()->getQuery());
        self::assertFalse($provider->capabilities()->supports(Capability::DNSSEC_ACTION_EXECUTE));
    }

    public function testRecordPaginationNormalizesTlsaAndIgnoresForeignTypes(): void
    {
        $first = [];
        for ($i = 1; $i <= 100; $i++) {
            $first[$i] = ['type' => 'TXT', 'host' => '@', 'record' => "value{$i}", 'ttl' => 300];
        }
        $provider = $this->provider([$first, [101 => ['type' => 'TLSA', 'host' => '_443._tcp.example.com.', 'record' => 'abcd', 'ttl' => 300, 'tlsa_usage' => '3', 'tlsa_selector' => '1', 'tlsa_matching_type' => '1'], 102 => ['type' => 'WR', 'record' => 'https://example.com']]]);
        $records  = $provider->listRecords('example.com');
        self::assertCount(101, $records);
        self::assertSame('', $records[0]->name);
        self::assertSame('101', $records[100]->id);
        self::assertSame('_443._tcp', $records[100]->name);
        self::assertSame('3 1 1 abcd', $records[100]->content);
    }

    public function testTlsaCreateAndUpdateUseOnlyTheSelectedRecordAndKeepDisabledState(): void
    {
        $provider = $this->provider([['status' => 'Success', 'data' => ['id' => '77']], ['type' => 'TLSA', 'status' => 0], ['status' => 'Success']]);
        $record   = new Record('', 'example.com', '_443._tcp.example.com.', RecordType::TLSA, 300, '3 1 1 abcd');
        $created  = $provider->createRecord($record);
        self::assertSame('77', $created->id);
        self::assertSame('_443._tcp', $this->body(0)['host']);
        self::assertSame('TLSA', $this->body(0)['record-type']);
        self::assertSame('3', $this->body(0)['tlsa_usage']);
        self::assertSame('abcd', $this->body(0)['record']);
        $provider->updateRecord($created);
        self::assertSame('77', $this->body(2)['record-id']);
        self::assertSame('0', $this->body(2)['status']);
        self::assertArrayNotHasKey('record-type', $this->body(2));
    }

    public function testRecordTypeChangesAreRejectedBeforeMutation(): void
    {
        $provider = $this->provider([['type' => 'TXT']]);
        $this->expectException(CapabilityException::class);
        try {
            $provider->updateRecord(new Record('7', 'example.com', '', RecordType::A, 300, '192.0.2.1'));
        } finally {
            self::assertCount(1, $this->historyArray());
        }
    }

    public function testDeleteDoesNotRewriteOrRemoveOtherRecords(): void
    {
        $provider = $this->provider([['status' => 'Success']]);
        $provider->deleteRecord('example.com', '42');
        self::assertCount(1, $this->historyArray());
        self::assertSame('/dns/delete-record.json', $this->historyArray()[0]['request']->getUri()->getPath());
        self::assertSame('42', $this->body(0)['record-id']);
    }

    public function testFailedStatusIsNotMistakenForAnEmptyList(): void
    {
        $provider = $this->provider([['status' => 'Failed', 'statusDescription' => 'secret&=value']]);
        $this->expectException(ProviderRequestException::class);
        $this->expectExceptionMessage('ClouDNS rejected the DNS request.');
        $provider->listZones();
    }
}

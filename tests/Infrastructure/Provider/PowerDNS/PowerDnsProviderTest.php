<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Provider\PowerDNS;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use TowerDNS\Domain\DNS\DnssecState;
use TowerDNS\Domain\DNS\Record;
use TowerDNS\Domain\DNS\RecordType;
use TowerDNS\Infrastructure\Provider\PowerDNS\PowerDnsProvider;

final class PowerDnsProviderTest extends TestCase
{
    /** @var list<RequestInterface> */
    private array $requests = [];

    public function testCreatesRecordWithExtendOnSupportedServers(): void
    {
        $provider = $this->provider([
            $this->jsonResponse(['version' => '5.0.2']),
            new Response(204),
        ]);

        $record = $provider->createRecord(new Record(
            id: '',
            zoneId: 'example.org.',
            name: 'www',
            type: RecordType::A,
            ttl: 300,
            content: '192.0.2.1',
        ));

        self::assertSame('example.org.|www|A|' . substr(hash('sha256', '192.0.2.1'), 0, 12), $record->id);
        self::assertCount(2, $this->requests);
        self::assertSame('test-api-key', $this->requests[0]->getHeaderLine('X-API-Key'));
        self::assertSame('/api/v1/servers/localhost/zones/example.org.', $this->requests[1]->getUri()->getPath());

        /** @var array{rrsets: list<array{changetype: string, records: list<array{content: string}>}>} $payload */
        $payload = json_decode((string) $this->requests[1]->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('EXTEND', $payload['rrsets'][0]['changetype']);
        self::assertSame('192.0.2.1', $payload['rrsets'][0]['records'][0]['content']);
    }

    public function testPreservesExistingRrsetOnOlderServers(): void
    {
        $provider = $this->provider([
            $this->jsonResponse(['version' => '4.8.0']),
            $this->jsonResponse(['rrsets' => [[
                'name'    => 'www.example.org.',
                'type'    => 'A',
                'ttl'     => 600,
                'records' => [['content' => '192.0.2.1']],
            ]]]),
            new Response(204),
        ]);

        $provider->createRecord(new Record(
            id: '',
            zoneId: 'example.org.',
            name: 'www',
            type: RecordType::A,
            ttl: 300,
            content: '192.0.2.2',
        ));

        /** @var array{rrsets: list<array{changetype: string, records: list<array{content: string}>}>} $payload */
        $payload = json_decode((string) $this->requests[2]->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('REPLACE', $payload['rrsets'][0]['changetype']);
        self::assertSame(['192.0.2.1', '192.0.2.2'], array_column($payload['rrsets'][0]['records'], 'content'));
    }

    public function testReadsNativeDnssecKeyStatus(): void
    {
        $provider = $this->provider([
            $this->jsonResponse(['dnssec' => true, 'serial' => 2026091001]),
            $this->jsonResponse([['id' => 42, 'active' => true, 'keytype' => 'csk']]),
        ]);

        $profile = $provider->getDnssecProfile('example.org.');

        self::assertSame(DnssecState::SIGNED, $profile->state);
        self::assertSame(1, $profile->metadata['key_count']);
        self::assertSame(2026091001, $profile->metadata['serial']);
    }

    public function testUsesRelativeNamesAtTheTowerDnsBoundary(): void
    {
        $provider = $this->provider([
            $this->jsonResponse(['rrsets' => [[
                'name'    => 'www.example.org.',
                'type'    => 'A',
                'ttl'     => 300,
                'records' => [['content' => '192.0.2.1']],
            ]]]),
        ]);

        $records = $provider->listRecords('example.org.');

        self::assertSame('www', $records[0]->name);
    }

    /**
     * @param list<Response> $responses
     */
    private function provider(array $responses): PowerDnsProvider
    {
        $this->requests = [];
        $stack          = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::tap(function (RequestInterface $request): void {
            $this->requests[] = $request;
        }));

        return new PowerDnsProvider(
            'https://pdns.test',
            'test-api-key',
            'localhost',
            new Client(['base_uri' => 'https://pdns.test/', 'handler' => $stack]),
        );
    }

    /** @param array<string, mixed>|list<array<string, mixed>> $payload */
    private function jsonResponse(array $payload): Response
    {
        return new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }
}

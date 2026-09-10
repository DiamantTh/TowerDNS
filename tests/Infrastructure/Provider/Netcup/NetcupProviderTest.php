<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Provider\Netcup;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use TowerDNS\Domain\DNS\Record;
use TowerDNS\Domain\DNS\RecordType;
use TowerDNS\Infrastructure\Provider\Netcup\NetcupApiClient;
use TowerDNS\Infrastructure\Provider\Netcup\NetcupProvider;

final class NetcupProviderTest extends TestCase
{
    /** @var list<RequestInterface> */
    private array $requests = [];

    public function testCreatesTlsaWithoutDiscardingOtherNetcupRecords(): void
    {
        $provider = $this->provider([
            $this->response(['apisessionid' => 'read-session']),
            $this->response(['dnsrecords' => [[
                'hostname'    => '@', 'type' => 'MX', 'priority' => '10',
                'destination' => 'mail.example.org', 'ttl' => 3600,
            ]]]),
            $this->response(),
            $this->response(['apisessionid' => 'write-session']),
            $this->response(),
            $this->response(),
        ]);

        $created = $provider->createRecord(new Record(
            id: '',
            zoneId: 'example.org',
            name: '_25._tcp.mail',
            type: RecordType::TLSA,
            ttl: 300,
            content: '3 0 1 deadbeef',
        ));

        self::assertSame('_25._tcp.mail', $created->name);
        self::assertSame(RecordType::TLSA, $created->type);
        self::assertCount(6, $this->requests);

        /** @var array{action: string, param: array{dnsrecordset: array{dnsrecords: list<array<string, mixed>>}}} $payload */
        $payload = json_decode((string) $this->requests[4]->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('updateDnsRecords', $payload['action']);
        self::assertSame('10', $payload['param']['dnsrecordset']['dnsrecords'][0]['priority']);
        self::assertSame('mail.example.org', $payload['param']['dnsrecordset']['dnsrecords'][0]['destination']);
        self::assertSame('TLSA', $payload['param']['dnsrecordset']['dnsrecords'][1]['type']);
        self::assertSame('3 0 1 deadbeef', $payload['param']['dnsrecordset']['dnsrecords'][1]['destination']);
    }

    public function testRejectsZonesOutsideConfiguredAllowlist(): void
    {
        $provider = $this->provider([]);

        $this->expectExceptionMessage('nicht im konfigurierten Zone-Allowlist');
        $provider->listRecords('not-example.org');
    }

    /** @param list<Response> $responses */
    private function provider(array $responses): NetcupProvider
    {
        $this->requests = [];
        $stack          = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::tap(function (RequestInterface $request): void {
            $this->requests[] = $request;
        }));
        $client = new NetcupApiClient(
            '12345',
            'api-key',
            'api-password',
            new Client(['base_uri' => 'https://netcup.test/', 'handler' => $stack]),
        );
        return new NetcupProvider($client, ['example.org']);
    }

    /** @param array<string, mixed> $data */
    private function response(array $data = []): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'statuscode'   => 2000,
            'responsedata' => $data,
        ], JSON_THROW_ON_ERROR));
    }
}

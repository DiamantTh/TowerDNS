<?php

declare(strict_types=1);

namespace TowerDNS\Module\Cloudflare\Tests;

require_once dirname(__DIR__) . '/module.php';

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use TowerDNS\Application\Contracts\Capability;
use TowerDNS\Domain\DNS\DNSRecordType;
use TowerDNS\Domain\DNS\Rrset;
use TowerDNS\Module\Cloudflare\CloudflareProvider;

final class CloudflareProviderTest extends TestCase
{
    /** @var list<RequestInterface> */
    private array $requests = [];

    public function testDoesNotAdvertiseZoneUpdatesWithoutAnUpdateOperation(): void
    {
        self::assertFalse($this->provider([])->capabilities()->supports(Capability::ZONE_UPDATE));
    }

    public function testReplaceRrsetUpdatesTtlAndReadsBack(): void
    {
        $provider = $this->provider([
            $this->json(['result' => [['id' => 'zone-1']]]),
            $this->json(['result' => [$this->record(300)], 'result_info' => ['total_pages' => 1]]),
            $this->json(['result' => $this->record(600)]),
            $this->json(['result' => [$this->record(600)], 'result_info' => ['total_pages' => 1]]),
        ]);

        $rrset    = new Rrset('example.org', '_443._tcp', DNSRecordType::parse('TLSA'), 600, ['3 1 1 aabb']);
        $observed = $provider->replaceRrset($rrset);

        self::assertSame(600, $observed->ttl);
        self::assertSame('PATCH', $this->requests[2]->getMethod());
        self::assertSame('/client/v4/zones/zone-1/dns_records/record-1', $this->requests[2]->getUri()->getPath());
    }

    /** @param list<Response> $responses */
    private function provider(array $responses): CloudflareProvider
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::tap(function (RequestInterface $request): void {
            $this->requests[] = $request;
        }));
        return new CloudflareProvider('test-token', new Client(['base_uri' => 'https://api.cloudflare.com/client/v4/', 'handler' => $stack]));
    }

    /** @param array<string, mixed> $data */
    private function json(array $data): Response
    {
        return new Response(200, [], json_encode(['success' => true] + $data, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function record(int $ttl): array
    {
        return ['id' => 'record-1', 'name' => '_443._tcp.example.org', 'type' => 'TLSA', 'ttl' => $ttl, 'content' => '3 1 1 aabb'];
    }
}

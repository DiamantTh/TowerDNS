<?php

declare(strict_types=1);

namespace TowerDNS\Module\DeSEC\Tests;

require_once dirname(__DIR__) . '/module.php';

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use TowerDNS\Domain\DNS\DNSRecordType;
use TowerDNS\Domain\DNS\Rrset;
use TowerDNS\Module\DeSEC\DeSECApiClient;
use TowerDNS\Module\DeSEC\DeSECProvider;

final class DeSECProviderTest extends TestCase
{
    /** @var list<RequestInterface> */
    private array $requests = [];

    public function testReplaceRrsetPatchesAndReadsBack(): void
    {
        $provider = $this->provider([
            $this->json([]),
            $this->json([['subname' => '_443._tcp', 'type' => 'TLSA', 'ttl' => 600, 'records' => ['3 1 1 aabb']]]),
        ]);

        $result = $provider->replaceRrset(new Rrset('example.org', '_443._tcp', DNSRecordType::parse('TLSA'), 600, ['3 1 1 aabb']));

        self::assertSame(['3 1 1 aabb'], $result->rdata);
        self::assertSame('PATCH', $this->requests[0]->getMethod());
        self::assertSame('/example.org/rrsets/_443._tcp/TLSA/', $this->requests[0]->getUri()->getPath());
    }

    /** @param list<Response> $responses */
    private function provider(array $responses): DeSECProvider
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::tap(function (RequestInterface $request): void {
            $this->requests[] = $request;
        }));
        return new DeSECProvider(new DeSECApiClient('token', new Client(['base_uri' => 'https://desec.test/', 'handler' => $stack])));
    }

    /** @param array<mixed> $body */
    private function json(array $body): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    }
}

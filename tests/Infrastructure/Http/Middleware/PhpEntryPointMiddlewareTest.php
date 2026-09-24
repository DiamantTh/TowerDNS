<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Http\Middleware;

use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Uri;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Infrastructure\Http\Middleware\PhpEntryPointMiddleware;

final class PhpEntryPointMiddlewareTest extends TestCase
{
    /** @return iterable<string, array{string, string, int, 3?: string}> */
    public static function paths(): iterable
    {
        yield 'dashboard' => ['/index.php', '/', 204];
        yield 'login' => ['/login.php', '/login', 204];
        yield 'role list' => ['/roles.php', '/roles', 204];
        yield 'role edit' => ['/roles.php?id=role-42', '/roles/role-42', 204];
        yield 'user edit' => ['/users.php?id=user-123', '/users/user-123', 204];
        yield 'account edit' => ['/accounts.php?id=107', '/accounts/107', 204];
        yield 'active zones' => ['/zones.php', '/zones', 204];
        yield 'account zones' => ['/zones.php?account=107', '/accounts/107/zones', 204];
        yield 'zone detail' => ['/zones.php?account=107&zone=21', '/accounts/107/zones/21', 204];
        yield 'records' => ['/records.php?account=107&zone=21', '/accounts/107/zones/21', 204, 'GET'];
        yield 'dnssec' => ['/dnssec.php?account=107&zone=21', '/accounts/107/zones/21/dnssec', 204];
        yield 'profile' => ['/profile.php', '/profile', 204];
        yield 'settings' => ['/settings.php', '/settings', 204];
        yield 'schema' => ['/settings.php?view=schema', '/settings/schema', 204];
        yield 'account members' => ['/accounts.php?id=107&view=members', '/accounts/107/members', 204];
        yield 'account providers' => ['/accounts.php?id=107&view=providers', '/accounts/107/providers', 204];
        yield 'delete user' => ['/users.php?id=user-123&action=delete', '/users/user-123/delete', 204];
        yield 'create zone' => ['/zones.php?account=107', '/accounts/107/zones', 204];
        yield 'delete zone' => ['/zones.php?account=107&zone=21&action=delete', '/accounts/107/zones/21/delete', 204];
        yield 'replace rrset' => ['/records.php?account=107&zone=21&action=replace', '/accounts/107/zones/21/rrsets', 204];
        yield 'delete rrset' => ['/records.php?account=107&zone=21&action=delete-rrset&owner=www&type=TXT', '/accounts/107/zones/21/rrsets/www/TXT/delete', 204];
        yield 'edit record' => ['/records.php?account=107&zone=21&record=44&action=edit', '/accounts/107/zones/21/records/44/edit', 204, 'GET'];
        yield 'record detail' => ['/records.php?account=107&zone=21&record=44', '/accounts/107/zones/21/records/44/edit', 204, 'GET'];
        yield 'legacy' => ['/roles/role-42', '/roles/role-42', 204];
        yield 'missing account' => ['/records.php?zone=21', '', 400];
        yield 'bad account' => ['/zones.php?account=-1', '', 400];
        yield 'overflowing account' => ['/zones.php?account=99999999999999999999999999999', '', 400];
        yield 'bad id' => ['/roles.php?id=../../admin', '', 400];
        yield 'duplicated id' => ['/roles.php?id=a&id=b', '', 400];
        yield 'duplicated zone' => ['/records.php?account=107&zone=21&zone=22', '', 400];
        yield 'bad action' => ['/records.php?account=107&zone=21&action=surprise', '', 400];
        yield 'rrset identity on read' => ['/records.php?account=107&zone=21&owner=www&type=TXT', '', 400, 'GET'];
        yield 'rrset deletion with record id' => ['/records.php?account=107&zone=21&record=44&action=delete-rrset&owner=www&type=TXT', '', 400];
        yield 'delete role without id' => ['/roles.php?action=delete', '', 400];
        yield 'delete zone without id' => ['/zones.php?account=107&action=delete', '', 400];
        yield 'account view without id' => ['/accounts.php?view=members', '', 400];
        yield 'array id' => ['/roles.php?id[]=a', '', 400];
        yield 'unknown php' => ['/unknown.php', '', 404];
    }

    #[DataProvider('paths')]
    public function testMapsOnlyKnownPages(string $uri, string $expectedPath, int $status, string $method = 'POST'): void
    {
        $next = new class implements RequestHandlerInterface {
            public ?ServerRequestInterface $seen = null;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->seen = $request;
                return new EmptyResponse(204);
            }
        };
        $request = new ServerRequest()->withUri(new Uri($uri));
        parse_str($request->getUri()->getQuery(), $query);
        $request  = $request->withQueryParams($query)->withMethod($method)->withParsedBody(['csrf_token' => 'test']);
        $response = new PhpEntryPointMiddleware()->process($request, $next);
        self::assertSame($status, $response->getStatusCode());
        if ($status === 204) {
            self::assertInstanceOf(ServerRequestInterface::class, $next->seen);
            self::assertSame($expectedPath, $next->seen->getUri()->getPath());
            self::assertSame($request->getUri()->getQuery(), $next->seen->getUri()->getQuery());
            self::assertSame($method, $next->seen->getMethod());
            self::assertSame(['csrf_token' => 'test'], $next->seen->getParsedBody());
        }
    }
}

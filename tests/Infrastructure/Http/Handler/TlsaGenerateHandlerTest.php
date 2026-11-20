<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Http\Handler;

use Laminas\Diactoros\ServerRequest;
use Mezzio\Csrf\CsrfGuardInterface;
use Mezzio\Csrf\CsrfMiddleware;
use PHPUnit\Framework\TestCase;
use TowerDNS\Application\DNS\TlsaGenerator;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Infrastructure\Http\Handler\TlsaGenerateHandler;

/** @psalm-api PHPUnit discovers these tests at runtime. */
final class TlsaGenerateHandlerTest extends TestCase
{
    public function testPreviewRequiresAnAuthenticatedUserAndTheDedicatedCsrfToken(): void
    {
        $handler = new TlsaGenerateHandler(new TlsaGenerator());
        $request = new ServerRequest()->withMethod('POST');
        self::assertSame(401, $handler->handle($request)->getStatusCode());
        $user    = new User('user-1', 'test@example.test');
        $request = $request->withAttribute(User::class, $user);
        self::assertSame(400, $handler->handle($request)->getStatusCode());

        $pem = file_get_contents(dirname(__DIR__, 3) . '/Fixtures/tlsa/ecdsa.pem');
        self::assertIsString($pem);
        $guard = $this->createMock(CsrfGuardInterface::class);
        $guard->expects(self::once())->method('validateToken')->with('synthetic', 'tlsa_preview')->willReturn(true);
        $guard->expects(self::once())->method('generateToken')->with('tlsa_preview')->willReturn('next');
        $request = $request->withAttribute(CsrfMiddleware::GUARD_ATTRIBUTE, $guard)->withParsedBody([
            'csrf_token' => 'synthetic', 'certificates' => $pem, 'usage' => '3', 'selector' => '1', 'matching' => '1', 'indexes' => '0',
        ]);
        $response = $handler->handle($request);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertJsonStringEqualsJsonString(
            '{"records":["3 1 1 57a5102f89d9420924abe803e29c1a90107fefc0de414ceda90a1460503bc604"],"csrf_token":"next","verification":"not_verified"}',
            (string) $response->getBody(),
        );
    }
}

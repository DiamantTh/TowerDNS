<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Http\Middleware;

use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\StreamFactory;
use Laminas\Translator\TranslatorInterface;
use Mezzio\Csrf\CsrfGuardInterface;
use Mezzio\Csrf\CsrfMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Domain\Account\AdminImpersonationSession;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Infrastructure\Http\Middleware\ImpersonationBannerMiddleware;

/** @psalm-api Runtime discovery by PHPUnit or local module loading is not statically visible. */
final class ImpersonationBannerMiddlewareTest extends TestCase
{
    public function testActiveSwitchIsVisiblyMarkedAndCanBeEndedWithCsrf(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('translate')->willReturnCallback(static fn(string $key): string => match ($key) {
            'admin-switch.active-banner' => 'Acting as %s; administrator %s.',
            'admin-switch.end'           => 'End switch',
            default                      => $key,
        });
        $guard = $this->createMock(CsrfGuardInterface::class);
        $guard->expects(self::once())->method('generateToken')->willReturn('csrf-token');

        $request = new ServerRequest()
            ->withMethod('GET')
            ->withAttribute('actor_user', new User('admin', 'admin@example.test', displayName: '<Root>'))
            ->withAttribute('effective_user', new User('target', 'target@example.test', displayName: 'Target & Co'))
            ->withAttribute('impersonation_session', new AdminImpersonationSession('switch', 'admin', 'target'))
            ->withAttribute(CsrfMiddleware::GUARD_ATTRIBUTE, $guard);
        $handler = new class implements RequestHandlerInterface {
            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new HtmlResponse('<html><body><div id="towerdns-app"></div></body></html>');
            }
        };

        $response = new ImpersonationBannerMiddleware(new StreamFactory(), $translator)->process($request, $handler);
        $html     = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('class="impersonation-banner"', $html);
        self::assertStringContainsString('action="/admin/switch/end"', $html);
        self::assertStringContainsString('name="csrf_token" value="csrf-token"', $html);
        self::assertStringContainsString('Target &amp; Co', $html);
        self::assertStringContainsString('&lt;Root&gt;', $html);
        self::assertStringNotContainsString('<Root>', $html);
        self::assertFalse($response->hasHeader('Content-Length'));
    }

    public function testDoesNotDecorateNonGetOrNonHtmlResponses(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::never())->method('translate');
        $guard = $this->createMock(CsrfGuardInterface::class);
        $guard->expects(self::never())->method('generateToken');
        $request = new ServerRequest()
            ->withMethod('POST')
            ->withAttribute('actor_user', new User('admin', 'admin@example.test'))
            ->withAttribute('effective_user', new User('target', 'target@example.test'))
            ->withAttribute('impersonation_session', new AdminImpersonationSession('switch', 'admin', 'target'))
            ->withAttribute(CsrfMiddleware::GUARD_ATTRIBUTE, $guard);
        $handler = new class implements RequestHandlerInterface {
            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new HtmlResponse('<html><body>Not decorated</body></html>');
            }
        };

        $response = new ImpersonationBannerMiddleware(new StreamFactory(), $translator)->process($request, $handler);

        self::assertSame('<html><body>Not decorated</body></html>', (string) $response->getBody());
    }
}

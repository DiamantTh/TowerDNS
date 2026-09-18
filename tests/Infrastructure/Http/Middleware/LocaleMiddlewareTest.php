<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Http\Middleware;

use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\ServerRequest;
use Laminas\I18n\Translator\Translator;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Infrastructure\Http\Middleware\LocaleMiddleware;

final class LocaleMiddlewareTest extends TestCase
{
    public function testUsesUserLocaleAndResetsFallbackForUnauthenticatedRequest(): void
    {
        $translator = new Translator();
        $middleware = new LocaleMiddleware($translator);
        $next       = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new EmptyResponse(204);
            }
        };
        $middleware->process(new ServerRequest()->withAttribute(User::class, new User('id', 'user@example.test', locale: 'de-DE')), $next);
        self::assertSame('de-DE', $translator->getLocale());
        $middleware->process(new ServerRequest(), $next);
        self::assertSame('en-GB', $translator->getLocale());
        new LocaleMiddleware($translator, 'de-DE')->process(new ServerRequest(), $next);
        self::assertSame('de-DE', $translator->getLocale());
    }
}

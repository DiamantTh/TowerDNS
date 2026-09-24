<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Http\Middleware;

use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Infrastructure\Http\Middleware\OwnProfileMiddleware;
use TowerDNS\Infrastructure\Http\Middleware\PhpEntryPointMiddleware;

final class OwnProfileMiddlewareTest extends TestCase
{
    public function testImpersonationCannotAccessProfileOrCredentialRoutes(): void
    {
        $middleware = new OwnProfileMiddleware();
        $next       = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new EmptyResponse(204);
            }
        };
        foreach (['/profile', '/profile/password', '/profile/totp', '/profile/webauthn/register/begin', '/profile/api-keys'] as $path) {
            $request = new ServerRequest()->withUri(new \Laminas\Diactoros\Uri($path))
                ->withAttribute('actor_user', new User('actor', 'actor@example.test'))
                ->withAttribute(User::class, new User('effective', 'effective@example.test'));
            self::assertSame(403, $middleware->process($request, $next)->getStatusCode(), $path);
        }
        $own = new ServerRequest()->withUri(new \Laminas\Diactoros\Uri('/profile/password'))
            ->withAttribute('actor_user', new User('actor', 'actor@example.test'))
            ->withAttribute(User::class, new User('actor', 'actor@example.test'));
        self::assertSame(204, $middleware->process($own, $next)->getStatusCode());
        self::assertSame(204, $middleware->process(new ServerRequest()->withUri(new \Laminas\Diactoros\Uri('/profile')), $next)->getStatusCode());
    }

    public function testPhysicalProfileEntryUsesTheSameImpersonationGuard(): void
    {
        $request = new ServerRequest()->withUri(new \Laminas\Diactoros\Uri('/profile.php'))
            ->withAttribute('actor_user', new User('actor', 'actor@example.test'))
            ->withAttribute(User::class, new User('effective', 'effective@example.test'));
        $next = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new EmptyResponse(204);
            }
        };
        $profile  = new OwnProfileMiddleware();
        $pipeline = new readonly class ($profile, $next) implements RequestHandlerInterface {
            public function __construct(private OwnProfileMiddleware $profile, private RequestHandlerInterface $next) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->profile->process($request, $this->next);
            }
        };
        self::assertSame(403, new PhpEntryPointMiddleware()->process($request, $pipeline)->getStatusCode());
    }
}

<?php

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Middleware;

use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Domain\Auth\User;

/** An impersonated identity must never manage another person's credentials or profile. */
final class OwnProfileMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if ($path === '/profile' || str_starts_with($path, '/profile/')) {
            $actor     = $request->getAttribute('actor_user');
            $effective = $request->getAttribute(User::class);
            if ($actor instanceof User && $effective instanceof User && $actor->id !== $effective->id) {
                return new EmptyResponse(403);
            }
        }

        return $handler->handle($request);
    }
}

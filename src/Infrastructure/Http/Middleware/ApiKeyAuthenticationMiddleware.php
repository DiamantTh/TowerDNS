<?php

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Middleware;

use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Application\Repository\ApiKeyRepositoryInterface;
use TowerDNS\Application\Repository\UserRepositoryInterface;
use TowerDNS\Domain\Auth\ApiKeyGrant;
use TowerDNS\Domain\Auth\User;

/** @psalm-api Registered as route middleware. */
final readonly class ApiKeyAuthenticationMiddleware implements MiddlewareInterface
{
    public function __construct(private ApiKeyRepositoryInterface $keys, private UserRepositoryInterface $users, private ForceHttpsMiddleware $https) {}

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->https->isSecure($request)) {
            return new JsonResponse(['error' => 'https_required'], 400, ['Cache-Control' => 'no-store']);
        }
        $header = $request->getHeaderLine('Authorization');
        if (preg_match('/^Bearer (tdns_[0-9a-f]{64})$/iD', $header, $parts) === 1) {
            $grant = $this->keys->findGrant(hash('sha256', $parts[1]), new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
            $user  = $grant instanceof ApiKeyGrant ? $this->users->findById($grant->userId) : null;
            if ($grant instanceof ApiKeyGrant && $user?->active === true) {
                return $handler->handle($request
                    ->withoutHeader('Authorization')
                    ->withoutAttribute('impersonation_session')
                    ->withAttribute(ApiKeyGrant::class, $grant)
                    ->withAttribute(User::class, $user)
                    ->withAttribute('actor_user', $user))
                    ->withHeader('Cache-Control', 'no-store');
            }
        }
        return new JsonResponse(['error' => 'invalid_api_key'], 401, ['WWW-Authenticate' => 'Bearer', 'Cache-Control' => 'no-store']);
    }
}

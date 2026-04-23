<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Middleware;

use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Domain\Auth\User;

/**
 * Guards a route group so that only authenticated users may proceed.
 *
 * Must be placed in the pipeline AFTER {@see AuthenticationMiddleware} so that
 * the {@see User} attribute is already resolved before this guard runs.
 *
 * Unauthenticated requests are redirected to {@see $loginPath} (default:
 * '/login').  API clients that send `Accept: application/json` receive a
 * 401 Unauthorized response instead of a redirect.
 *
 * Example pipeline registration:
 * ```php
 * $app->pipe(SessionMiddleware::class);
 * $app->pipe(AuthenticationMiddleware::class);
 * $app->pipe(RequireAuthMiddleware::class);  // protects everything below
 * ```
 */
final class RequireAuthMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly string $loginPath = '/login')
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $request->getAttribute(User::class);

        if (!$user instanceof User) {
            // JSON clients get a 401 instead of an HTML redirect.
            $accept = $request->getHeaderLine('Accept');
            if (str_contains($accept, 'application/json')) {
                return new \Laminas\Diactoros\Response\JsonResponse(
                    ['error' => 'Unauthenticated'],
                    401,
                );
            }

            return new RedirectResponse($this->loginPath);
        }

        return $handler->handle($request);
    }
}

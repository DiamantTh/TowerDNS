<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Middleware;

use Mezzio\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Application\Repository\UserRepositoryInterface;
use TowerDNS\Domain\Auth\User;

/**
 * Resolves the authenticated user from the session and attaches it to the
 * request as an attribute keyed by {@see User::class}.
 *
 * Must be placed in the pipeline AFTER {@see \Mezzio\Session\SessionMiddleware}
 * so that the session attribute is already present on the request.
 *
 * Downstream handlers and services retrieve the user via:
 * ```php
 * $user = $request->getAttribute(User::class);
 * ```
 * The attribute is absent (null) when no valid session exists, the stored
 * user_id is unknown, or the account has been deactivated.
 */
final readonly class AuthenticationMiddleware implements MiddlewareInterface
{
    public function __construct(
        private UserRepositoryInterface $users,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $session = $request->getAttribute(SessionInterface::class);

        if ($session instanceof SessionInterface) {
            $userId = $session->get('user_id');

            if (is_string($userId) && $userId !== '') {
                $user = $this->users->findById($userId);

                if ($user instanceof User) {
                    $request = $request->withAttribute(User::class, $user);
                }
            }
        }

        return $handler->handle($request);
    }
}

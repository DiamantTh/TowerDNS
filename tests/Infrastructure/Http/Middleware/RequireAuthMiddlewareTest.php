<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Http\Middleware;

use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\ServerRequest;
use Mezzio\Authentication\UserInterface;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Infrastructure\Http\Middleware\RequireAuthMiddleware;
use TowerDNS\Infrastructure\Http\TowerDNSAuthenticatedUser;

/** @psalm-api Runtime discovery by PHPUnit or local module loading is not statically visible. */
final class RequireAuthMiddlewareTest extends TestCase
{
    public function testRejectsMismatchedMezzioAndDomainIdentities(): void
    {
        $request = new ServerRequest()
            ->withAttribute(User::class, new User('domain-user', 'domain@example.test'))
            ->withAttribute(UserInterface::class, new TowerDNSAuthenticatedUser(new User('other-user', 'other@example.test')));
        $handler = new class implements RequestHandlerInterface {
            public bool $called = false;
            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->called = true;
                return new EmptyResponse(204);
            }
        };

        $response = new RequireAuthMiddleware()->process($request, $handler);

        self::assertSame(302, $response->getStatusCode());
        self::assertFalse($handler->called);
    }

    public function testAcceptsTheCanonicalMatchingIdentity(): void
    {
        $user    = new User('current-user', 'current@example.test');
        $request = new ServerRequest()
            ->withAttribute(User::class, $user)
            ->withAttribute(UserInterface::class, new TowerDNSAuthenticatedUser($user));
        $handler = new class implements RequestHandlerInterface {
            public bool $called = false;
            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->called = true;
                return new EmptyResponse(204);
            }
        };

        $response = new RequireAuthMiddleware()->process($request, $handler);

        self::assertSame(204, $response->getStatusCode());
        self::assertTrue($handler->called);
    }
}

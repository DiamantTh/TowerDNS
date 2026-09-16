<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Http\Middleware;

use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\ServerRequest;
use Mezzio\Session\Session;
use Mezzio\Session\SessionInterface;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Application\Repository\AccountRepositoryInterface;
use TowerDNS\Application\Repository\AdminImpersonationSessionRepositoryInterface;
use TowerDNS\Application\Repository\UserRepositoryInterface;
use TowerDNS\Application\Services\ActiveAccountService;
use TowerDNS\Domain\Account\Account;
use TowerDNS\Domain\Account\AdminImpersonationSession;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Infrastructure\Http\ActiveAccountContext;
use TowerDNS\Infrastructure\Http\ImpersonationContext;
use TowerDNS\Infrastructure\Http\Middleware\AuthenticationMiddleware;
use TowerDNS\Infrastructure\Http\SessionSecurity;

final class AuthenticationMiddlewareTest extends TestCase
{
    public function testImpersonationAccountIsAnExistingMembershipScopeLimit(): void
    {
        $actor     = new User('actor', 'actor@example.test');
        $effective = new User('effective', 'effective@example.test');
        $account   = new Account(10, 'Scoped', 'scoped', 'effective', true, '2026-09-16 00:00:00');
        $request   = $this->requestWithSession([
            'user_id'                 => $actor->id,
            'authenticated_at'        => 1000,
            'last_activity_at'        => 1000,
            'admin_switch_session_id' => 'switch-1',
            'active_account_id'       => 99,
        ]);

        $accounts = $this->createMock(AccountRepositoryInterface::class);
        $accounts->method('findById')->with(10)->willReturn($account);
        $accounts->method('findByUserId')->with($effective->id)->willReturn([$account]);

        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findById')->willReturnCallback(static fn(string $id): ?User => match ($id) {
            'actor'     => $actor,
            'effective' => $effective,
            default     => null,
        });

        $switches = $this->createMock(AdminImpersonationSessionRepositoryInterface::class);
        $switches->method('findById')->with('switch-1')->willReturn(new AdminImpersonationSession(
            'switch-1',
            $actor->id,
            $effective->id,
            $account->id,
            'Support request',
            date('Y-m-d H:i:s'),
            date('Y-m-d H:i:s', time() + 3600),
        ));

        $capturingHandler = new class implements RequestHandlerInterface {
            public ?ServerRequestInterface $request = null;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->request = $request;
                return new EmptyResponse();
            }
        };

        $middleware = new AuthenticationMiddleware(
            $users,
            $switches,
            $accounts,
            new SessionSecurity($this->clock(1001)),
            new ActiveAccountService($accounts),
        );

        $middleware->process($request, $capturingHandler);

        self::assertNotNull($capturingHandler->request);
        $captured = $capturingHandler->request;
        self::assertSame($actor, $captured->getAttribute('actor_user'));
        self::assertSame($effective, $captured->getAttribute(User::class));
        self::assertSame(10, $captured->getAttribute(ActiveAccountContext::class)?->account?->id);
        self::assertSame(10, $captured->getAttribute(ImpersonationContext::class)?->effectiveAccountId);
        self::assertFalse($this->sessionFrom($request)->has('active_account_id'));
    }

    public function testImpersonationCannotGrantAnAccountWithoutEffectiveUserMembership(): void
    {
        $actor     = new User('actor', 'actor@example.test');
        $effective = new User('effective', 'effective@example.test');
        $request   = $this->requestWithSession([
            'user_id'                 => $actor->id,
            'authenticated_at'        => 1000,
            'last_activity_at'        => 1000,
            'admin_switch_session_id' => 'switch-1',
        ]);
        $account = new Account(10, 'Restricted', 'restricted', 'other', true, '2026-09-16 00:00:00');

        $accounts = $this->createMock(AccountRepositoryInterface::class);
        $accounts->method('findById')->with(10)->willReturn($account);
        $accounts->method('findByUserId')->willReturn([]);

        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findById')->willReturnCallback(static fn(string $id): ?User => match ($id) {
            'actor'     => $actor,
            'effective' => $effective,
            default     => null,
        });

        $switches = $this->createMock(AdminImpersonationSessionRepositoryInterface::class);
        $switches->method('findById')->willReturn(new AdminImpersonationSession(
            'switch-1',
            $actor->id,
            $effective->id,
            10,
            'Support request',
            date('Y-m-d H:i:s'),
            date('Y-m-d H:i:s', time() + 3600),
        ));

        $capturingHandler = new class implements RequestHandlerInterface {
            public ?ServerRequestInterface $request = null;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->request = $request;
                return new EmptyResponse();
            }
        };

        new AuthenticationMiddleware($users, $switches, $accounts, new SessionSecurity($this->clock(1001)), new ActiveAccountService($accounts))
            ->process($request, $capturingHandler);

        self::assertNotNull($capturingHandler->request);
        $captured = $capturingHandler->request;
        self::assertSame($actor, $captured->getAttribute(User::class));
        self::assertNull($captured->getAttribute('impersonation_session'));
        self::assertFalse($this->sessionFrom($request)->has('admin_switch_session_id'));
    }

    /** @param array<string, mixed> $state */
    private function requestWithSession(array $state): ServerRequestInterface
    {
        return new ServerRequest()->withAttribute(SessionInterface::class, new Session($state));
    }

    private function sessionFrom(ServerRequestInterface $request): SessionInterface
    {
        /** @var SessionInterface $session */
        $session = $request->getAttribute(SessionInterface::class);
        return $session;
    }

    private function clock(int $timestamp): ClockInterface
    {
        return new readonly class ($timestamp) implements ClockInterface {
            public function __construct(private int $timestamp) {}

            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable()->setTimestamp($this->timestamp);
            }
        };
    }
}

<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Http\Middleware;

use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\ServerRequest;
use Mezzio\Authentication\UserInterface;
use Mezzio\Session\Session;
use Mezzio\Session\SessionInterface;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Application\Repository\AccountRepositoryInterface;
use TowerDNS\Application\Repository\AdminImpersonationSessionRepositoryInterface;
use TowerDNS\Application\Repository\AuditLogRepositoryInterface;
use TowerDNS\Application\Repository\UserRepositoryInterface;
use TowerDNS\Application\Services\ActiveAccountService;
use TowerDNS\Application\Services\AuditLogService;
use TowerDNS\Application\Services\AuthorizationService;
use TowerDNS\Domain\Account\Account;
use TowerDNS\Domain\Account\AdminImpersonationSession;
use TowerDNS\Domain\Auth\Permission;
use TowerDNS\Domain\Auth\Role;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Infrastructure\Http\ActiveAccountContext;
use TowerDNS\Infrastructure\Http\ImpersonationContext;
use TowerDNS\Infrastructure\Http\Middleware\AuthenticationMiddleware;
use TowerDNS\Infrastructure\Http\SessionSecurity;
use TowerDNS\Infrastructure\Http\TowerDNSSessionAuthentication;

/** @psalm-api Runtime discovery by PHPUnit or local module loading is not statically visible. */
final class AuthenticationMiddlewareTest extends TestCase
{
    public function testDownstreamAuthorizationUsesOnlyTheEffectiveSwitchIdentity(): void
    {
        $actor = new User('actor', 'actor@example.test', [
            new Role('superadmin', 'Superadmin', [Permission::RECORD_DELETE], isBuiltIn: true),
        ]);
        $effective = new User('effective', 'effective@example.test', [
            new Role('viewer', 'Read-only', [Permission::RECORD_READ]),
        ]);
        $request = $this->requestWithSession([
            'user_id'                 => $actor->id,
            'authenticated_at'        => 1000,
            'last_activity_at'        => 1000,
            'admin_switch_session_id' => 'switch-1',
        ]);

        $accounts = $this->createMock(AccountRepositoryInterface::class);
        $accounts->method('findByUserId')->with($effective->id)->willReturn([]);
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
            null,
            'Read-only support request',
            date('Y-m-d H:i:s'),
            date('Y-m-d H:i:s', time() + 3600),
        ));

        $authorization = new AuthorizationService();
        self::assertTrue($authorization->isGranted($actor, Permission::RECORD_DELETE));
        self::assertFalse($authorization->isGranted($effective, Permission::RECORD_DELETE));
        $handler = new class ($authorization) implements RequestHandlerInterface {
            public ?User $receivedUser = null;

            public function __construct(private readonly AuthorizationService $authorization) {}

            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $user               = $request->getAttribute(User::class);
                $this->receivedUser = $user instanceof User ? $user : null;
                $allowed            = $user instanceof User && $this->authorization->isGranted($user, Permission::RECORD_DELETE);
                return new EmptyResponse($allowed ? 200 : 204);
            }
        };

        $response = new AuthenticationMiddleware(
            $users,
            $switches,
            $accounts,
            new TowerDNSSessionAuthentication($users, new SessionSecurity($this->clock(1001))),
            $authorization,
            new ActiveAccountService($accounts),
            $this->auditLog(),
        )->process($request, $handler);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame($effective, $handler->receivedUser);
    }

    public function testImpersonationAccountIsAnExistingMembershipScopeLimit(): void
    {
        $actor     = new User('actor', 'actor@example.test', [new Role('superadmin', 'Superadmin', [], isBuiltIn: true)]);
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

            #[\Override]
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
            new TowerDNSSessionAuthentication($users, new SessionSecurity($this->clock(1001))),
            new AuthorizationService(),
            new ActiveAccountService($accounts),
            $this->auditLog(),
        );

        $middleware->process($request, $capturingHandler);

        self::assertNotNull($capturingHandler->request);
        $captured = $capturingHandler->request;
        self::assertSame($actor, $captured->getAttribute('actor_user'));
        self::assertSame($effective, $captured->getAttribute(User::class));
        self::assertInstanceOf(UserInterface::class, $captured->getAttribute(UserInterface::class));
        self::assertSame($effective->id, $captured->getAttribute(UserInterface::class)->getIdentity());
        self::assertSame([], $captured->getAttribute(UserInterface::class)->getDetails());
        self::assertSame(10, $captured->getAttribute(ActiveAccountContext::class)?->account?->id);
        self::assertSame(10, $captured->getAttribute(ImpersonationContext::class)?->effectiveAccountId);
        self::assertFalse($this->sessionFrom($request)->has('active_account_id'));
    }

    public function testImpersonationCannotGrantAnAccountWithoutEffectiveUserMembership(): void
    {
        $actor     = new User('actor', 'actor@example.test', [new Role('superadmin', 'Superadmin', [], isBuiltIn: true)]);
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
        $switches->expects(self::once())->method('end')->with('switch-1', self::callback('is_string'));
        $auditRepository = $this->createMock(AuditLogRepositoryInterface::class);
        $auditRepository->expects(self::once())->method('append')->with(
            self::callback(static fn(\TowerDNS\Domain\Account\AuditLogEntry $entry): bool => $entry->action === 'admin.switch.invalidated'
                && $entry->actorUserId                                                                      === 'actor'
                && $entry->effectiveUserId                                                                  === 'effective'),
            self::callback('is_string'),
        );

        $capturingHandler = new class implements RequestHandlerInterface {
            public ?ServerRequestInterface $request = null;

            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->request = $request;
                return new EmptyResponse();
            }
        };

        $response = new AuthenticationMiddleware($users, $switches, $accounts, new TowerDNSSessionAuthentication($users, new SessionSecurity($this->clock(1001))), new AuthorizationService(), new ActiveAccountService($accounts), new AuditLogService($auditRepository))
            ->process($request, $capturingHandler);

        self::assertSame(403, $response->getStatusCode());
        self::assertNull($capturingHandler->request);
        self::assertFalse($this->sessionFrom($request)->has('admin_switch_session_id'));
    }

    public function testLegacySwitchWithoutAnExplicitTargetIsInvalidated(): void
    {
        $actor   = new User('actor', 'actor@example.test', [new Role('superadmin', 'Superadmin', [], isBuiltIn: true)]);
        $request = $this->requestWithSession([
            'user_id'                 => $actor->id,
            'authenticated_at'        => 1000,
            'last_activity_at'        => 1000,
            'admin_switch_session_id' => 'legacy-switch',
        ]);

        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findById')->with('actor')->willReturn($actor);
        $accounts = $this->createMock(AccountRepositoryInterface::class);
        $switches = $this->createMock(AdminImpersonationSessionRepositoryInterface::class);
        $switches->method('findById')->with('legacy-switch')->willReturn(new AdminImpersonationSession(
            'legacy-switch',
            $actor->id,
            null,
            null,
            'Legacy targetless switch',
            date('Y-m-d H:i:s'),
            date('Y-m-d H:i:s', time() + 3600),
        ));
        $switches->expects(self::once())->method('end')->with('legacy-switch', self::callback('is_string'));

        $capturingHandler = new class implements RequestHandlerInterface {
            public ?ServerRequestInterface $request = null;
            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->request = $request;
                return new EmptyResponse();
            }
        };

        $response = new AuthenticationMiddleware($users, $switches, $accounts, new TowerDNSSessionAuthentication($users, new SessionSecurity($this->clock(1001))), new AuthorizationService(), new ActiveAccountService($accounts), $this->auditLog())
            ->process($request, $capturingHandler);

        self::assertSame(403, $response->getStatusCode());
        self::assertNull($capturingHandler->request);
        self::assertFalse($this->sessionFrom($request)->has('admin_switch_session_id'));
    }

    public function testSwitchIsInvalidatedWhenOriginalActorLosesSuperadminRole(): void
    {
        $actor     = new User('actor', 'actor@example.test', [new Role('iam-admin', 'IAM administrator', [Permission::USER_MANAGE, Permission::ROLE_MANAGE])]);
        $effective = new User('effective', 'effective@example.test');
        $request   = $this->requestWithSession([
            'user_id'                 => $actor->id,
            'authenticated_at'        => 1000,
            'last_activity_at'        => 1000,
            'admin_switch_session_id' => 'switch-1',
        ]);
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findById')->willReturnCallback(static fn(string $id): ?User => match ($id) {
            'actor'     => $actor,
            'effective' => $effective,
            default     => null,
        });
        $accounts = $this->createMock(AccountRepositoryInterface::class);
        $switches = $this->createMock(AdminImpersonationSessionRepositoryInterface::class);
        $switches->method('findById')->with('switch-1')->willReturn(new AdminImpersonationSession(
            'switch-1',
            $actor->id,
            $effective->id,
            null,
            'Support request',
            date('Y-m-d H:i:s'),
            date('Y-m-d H:i:s', time() + 3600),
        ));
        $switches->expects(self::once())->method('end')->with('switch-1', self::callback('is_string'));
        $handler = new class implements RequestHandlerInterface {
            public ?ServerRequestInterface $request = null;
            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->request = $request;
                return new EmptyResponse();
            }
        };

        $response = new AuthenticationMiddleware(
            $users,
            $switches,
            $accounts,
            new TowerDNSSessionAuthentication($users, new SessionSecurity($this->clock(1001))),
            new AuthorizationService(),
            new ActiveAccountService($accounts),
            $this->auditLog(),
        )->process($request, $handler);

        self::assertSame(403, $response->getStatusCode());
        self::assertNull($handler->request);
        self::assertFalse($this->sessionFrom($request)->has('admin_switch_session_id'));
    }

    public function testDeletedOrInactiveSessionIdentityIsCleared(): void
    {
        $request = $this->requestWithSession([
            'user_id'          => 'disabled-user',
            'authenticated_at' => 1000,
            'last_activity_at' => 1000,
        ]);
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findById')->with('disabled-user')->willReturn(null);
        $accounts = $this->createMock(AccountRepositoryInterface::class);
        $switches = $this->createMock(AdminImpersonationSessionRepositoryInterface::class);
        $handler  = new class implements RequestHandlerInterface {
            public ?ServerRequestInterface $request = null;
            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->request = $request;
                return new EmptyResponse();
            }
        };

        new AuthenticationMiddleware(
            $users,
            $switches,
            $accounts,
            new TowerDNSSessionAuthentication($users, new SessionSecurity($this->clock(1001))),
            new AuthorizationService(),
            new ActiveAccountService($accounts),
            $this->auditLog(),
        )->process($request, $handler);

        self::assertNotNull($handler->request);
        self::assertNull($handler->request->getAttribute(User::class));
        self::assertFalse($this->sessionFrom($request)->has('user_id'));
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

            #[\Override]
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable()->setTimestamp($this->timestamp);
            }
        };
    }

    private function auditLog(): AuditLogService
    {
        return new AuditLogService($this->createMock(AuditLogRepositoryInterface::class));
    }
}

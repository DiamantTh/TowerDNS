<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Http\Middleware;

use Doctrine\DBAL\DriverManager;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Uri;
use Mezzio\Session\Session;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Application\Repository\TotpCredentialRepositoryInterface;
use TowerDNS\Application\Repository\WebAuthnCredentialRepositoryInterface;
use TowerDNS\Application\Services\AccountRecoveryService;
use TowerDNS\Application\Services\AuditLogService;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Infrastructure\Http\Middleware\RecoverySessionMiddleware;
use TowerDNS\Infrastructure\Http\SessionSecurity;
use TowerDNS\Infrastructure\Persistence\DbalAccountRecoveryRepository;
use TowerDNS\Infrastructure\Persistence\DbalAuditLogRepository;
use TowerDNS\Infrastructure\Persistence\DbalPasswordResetTokenRepository;
use TowerDNS\Infrastructure\Persistence\DbalTransactionRunner;
use TowerDNS\Infrastructure\Persistence\DbalUserRepository;
use TowerDNS\Infrastructure\Persistence\SchemaManager;

/** @psalm-api Runtime discovery by PHPUnit or local module loading is not statically visible. */
final class RecoverySessionMiddlewareTest extends TestCase
{
    public function testRestrictedSessionBlocksNormalApplicationRoutes(): void
    {
        [$middleware, $state, $session] = $this->activeRecoverySession();
        $request                        = new ServerRequest()->withUri(new Uri('/profile'))->withHeader('Accept', 'application/json')
            ->withAttribute('session', $session)
            ->withAttribute(\Mezzio\Session\SessionInterface::class, $session);
        $next = $this->createMock(RequestHandlerInterface::class);
        $next->expects(self::never())->method('handle');

        $response = $middleware->process($request, $next);

        self::assertSame(403, $response->getStatusCode());
        self::assertFalse($session->has('user_id'));
        self::assertSame($state['recovery_id'], $session->get('account_recovery')['recovery_id']);
    }

    public function testOnlyRecoveryPageReceivesTheTargetIdentityWithoutNormalLoginState(): void
    {
        [$middleware, $state, $session] = $this->activeRecoverySession();
        $request                        = new ServerRequest()->withUri(new Uri('/account/recovery'))
            ->withAttribute('session', $session)
            ->withAttribute(\Mezzio\Session\SessionInterface::class, $session);
        $next = $this->createMock(RequestHandlerInterface::class);
        $next->expects(self::once())->method('handle')->willReturnCallback(static function (ServerRequestInterface $request) use ($state): EmptyResponse {
            self::assertInstanceOf(User::class, $request->getAttribute(User::class));
            self::assertSame($state, $request->getAttribute('account_recovery'));
            self::assertFalse($request->getAttribute(\Mezzio\Session\SessionInterface::class)->has('user_id'));
            return new EmptyResponse();
        });

        self::assertSame(204, $middleware->process($request, $next)->getStatusCode());
    }

    public function testRecoveryMutationRoutesAreNotAvailableWithoutRestrictedRecoveryState(): void
    {
        [$middleware, , $session] = $this->activeRecoverySession();
        $session->unset('account_recovery');
        $request = new ServerRequest()->withUri(new Uri('/account/recovery/webauthn/register/begin'))
            ->withAttribute('session', $session)
            ->withAttribute(\Mezzio\Session\SessionInterface::class, $session);
        $next = $this->createMock(RequestHandlerInterface::class);
        $next->expects(self::never())->method('handle');

        self::assertSame(403, $middleware->process($request, $next)->getStatusCode());
    }

    /** @return array{RecoverySessionMiddleware,array{user_id:string,recovery_id:string,session_id_hash:string,purpose:string},Session} */
    private function activeRecoverySession(): array
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $schema     = new SchemaManager($connection);
        $schema->createTablesIfNotExist();
        $schema->seedSystemRoles();
        $schema->seedFirstUser('user-1', 'recovery@example.test', '');
        $users      = new DbalUserRepository($connection, $this->clock());
        $recoveries = new DbalAccountRecoveryRepository($connection);
        $webAuthn   = $this->createMock(WebAuthnCredentialRepositoryInterface::class);
        $webAuthn->method('findByUserId')->willReturn([]);
        $webAuthn->method('countByUserId')->willReturn(0);
        $totp = $this->createMock(TotpCredentialRepositoryInterface::class);
        $totp->method('findByUserId')->willReturn([]);
        $audit   = new AuditLogService(new DbalAuditLogRepository($connection));
        $service = new AccountRecoveryService(
            $recoveries,
            new DbalPasswordResetTokenRepository($connection),
            $webAuthn,
            $totp,
            new DbalTransactionRunner($connection),
            $audit,
            $this->clock(),
        );
        $user = $users->findById('user-1');
        self::assertInstanceOf(User::class, $user);
        $ticket      = $service->authorize($user, 'user-1', new \TowerDNS\Application\DTO\AuditContext('user-1', 'user-1'));
        $session     = new Session([], 'recovery-session');
        $sessionHash = hash('sha256', $session->getId());
        $started     = $service->redeem($ticket['raw_ticket'], $sessionHash, new ServerRequest());
        self::assertNotNull($started);
        $state = [
            'user_id'         => 'user-1',
            'recovery_id'     => $started['id'],
            'session_id_hash' => $sessionHash,
            'purpose'         => 'credential_recovery',
        ];
        $session->set('account_recovery', $state);
        $middleware = new RecoverySessionMiddleware($service, $users, new SessionSecurity($this->clock()), $audit);
        return [$middleware, $state, $session];
    }

    private function clock(): ClockInterface
    {
        return new readonly class implements ClockInterface {
            #[\Override]
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-10-07 12:00:00');
            }
        };
    }
}

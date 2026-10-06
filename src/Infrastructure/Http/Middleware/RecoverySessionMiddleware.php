<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Middleware;

use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Mezzio\Authentication\UserInterface;
use Mezzio\Session\SessionIdentifierAwareInterface;
use Mezzio\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Application\Repository\UserRepositoryInterface;
use TowerDNS\Application\Services\AccountRecoveryService;
use TowerDNS\Application\Services\AuditLogService;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Infrastructure\Http\SessionSecurity;
use TowerDNS\Infrastructure\Http\TowerDNSAuthenticatedUser;

/** Turns the normal session into an explicitly restricted recovery context. */
final readonly class RecoverySessionMiddleware implements MiddlewareInterface
{
    private const array ALLOWED_PATHS = [
        '/account/recovery',
        '/account/recovery/webauthn/register/begin',
        '/account/recovery/webauthn/register/finish',
        '/account/recovery/complete',
        '/account/recovery/abort',
    ];

    public function __construct(
        private AccountRecoveryService $recoveries,
        private UserRepositoryInterface $users,
        private SessionSecurity $sessions,
        private AuditLogService $audit,
    ) {}

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $session = $request->getAttribute(SessionInterface::class);
        $path    = $request->getUri()->getPath();
        if (!$session instanceof SessionInterface || !$session->has('account_recovery')) {
            if (in_array($path, array_slice(self::ALLOWED_PATHS, 1), true)) {
                return new EmptyResponse(403);
            }
            return $handler->handle($request);
        }
        $state = $session->get('account_recovery');
        if (!is_array($state)
            || !is_string($state['user_id'] ?? null)
            || !is_string($state['recovery_id'] ?? null)
            || !is_string($state['session_id_hash'] ?? null)
            || ($state['purpose'] ?? null) !== 'credential_recovery'
            || $session->has('user_id')
            || $session->has('admin_switch_session_id')
            || !$session instanceof SessionIdentifierAwareInterface) {
            $this->sessions->invalidate($session);
            return new EmptyResponse(403);
        }

        $sessionIdHash = hash('sha256', $session->getId());
        if (!hash_equals($state['session_id_hash'], $sessionIdHash)
            || !$this->recoveries->validateSession($state['user_id'], $state['recovery_id'], $sessionIdHash)) {
            $this->sessions->endRecovery($session);
            return new RedirectResponse('/account/recovery?expired=1');
        }

        $user = $this->users->findById($state['user_id']);
        if (!$user instanceof User) {
            $this->sessions->endRecovery($session);
            return new EmptyResponse(403);
        }

        if (!in_array($path, self::ALLOWED_PATHS, true)) {
            $this->audit->record($request, 'security.account_recovery.endpoint.blocked', 'user', $user->id, $user->id, metadata: ['path' => substr($path, 0, 160)]);
            if (str_contains($request->getHeaderLine('Accept'), 'application/json')) {
                return new JsonResponse(['error' => 'Only account recovery actions are available in this session.'], 403);
            }
            return new RedirectResponse('/account/recovery?restricted=1');
        }

        $identity = new TowerDNSAuthenticatedUser($user);
        return $handler->handle(
            $request
                ->withAttribute(User::class, $user)
                ->withAttribute(UserInterface::class, $identity)
                ->withAttribute('actor_user', $user)
                ->withAttribute('account_recovery', $state),
        );
    }
}

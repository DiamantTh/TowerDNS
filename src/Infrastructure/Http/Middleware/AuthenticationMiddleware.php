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
use TowerDNS\Application\Repository\AccountRepositoryInterface;
use TowerDNS\Application\Repository\AdminImpersonationSessionRepositoryInterface;
use TowerDNS\Application\Repository\UserRepositoryInterface;
use TowerDNS\Application\Services\ActiveAccountService;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Infrastructure\Http\ActiveAccountContext;
use TowerDNS\Infrastructure\Http\ImpersonationContext;
use TowerDNS\Infrastructure\Http\SessionSecurity;

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
        private AdminImpersonationSessionRepositoryInterface $impersonationSessions,
        private AccountRepositoryInterface $accounts,
        private SessionSecurity $sessionSecurity,
        private ActiveAccountService $activeAccounts,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $session = $request->getAttribute(SessionInterface::class);

        if ($session instanceof SessionInterface) {
            $userId = $this->sessionSecurity->authenticatedUserId($session);

            if (is_string($userId) && $userId !== '') {
                $user = $this->users->findById($userId);

                if ($user instanceof User) {
                    $request  = $request->withAttribute(User::class, $user)->withAttribute('actor_user', $user)->withAttribute(ImpersonationContext::class, new ImpersonationContext($user, $user));
                    $switchId = $session->get('admin_switch_session_id');
                    if (is_string($switchId) && $switchId !== '') {
                        $switch = $this->impersonationSessions->findById($switchId);
                        if (!$switch instanceof \TowerDNS\Domain\Account\AdminImpersonationSession || $switch->actorUserId !== $user->id || $switch->endedAt !== null || new \DateTimeImmutable($switch->expiresAt) <= new \DateTimeImmutable()) {
                            $session->unset('admin_switch_session_id');
                        } elseif ($switch->effectiveAccountId !== null && (!($account = $this->accounts->findById($switch->effectiveAccountId)) instanceof \TowerDNS\Domain\Account\Account || !$account->isActive)) {
                            $session->unset('admin_switch_session_id');
                        } elseif ($switch->effectiveUserId !== null) {
                            $effective = $this->users->findById($switch->effectiveUserId);
                            if ($effective instanceof User) {
                                $request = $request->withAttribute(User::class, $effective)->withAttribute('effective_user', $effective)->withAttribute('impersonation_session', $switch)->withAttribute(ImpersonationContext::class, new ImpersonationContext($user, $effective, $switch->effectiveAccountId, $switch));
                            } else {
                                $session->unset('admin_switch_session_id');
                            }
                        }
                    }
                    /** @var User $effectiveUser */
                    $effectiveUser = $request->getAttribute(User::class);
                    $activeId      = $session->get('active_account_id');
                    $active        = is_int($activeId) || (is_string($activeId) && ctype_digit($activeId))
                        ? $this->activeAccounts->resolve($effectiveUser, (int) $activeId)
                        : null;
                    if ($active === null && $activeId !== null) {
                        $session->unset('active_account_id');
                    }
                    $request = $request->withAttribute(ActiveAccountContext::class, new ActiveAccountContext($active));
                }
            }
        }

        return $handler->handle($request);
    }
}

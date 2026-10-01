<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Middleware;

use Mezzio\Authentication\AuthenticationInterface;
use Mezzio\Authentication\UserInterface;
use Mezzio\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Application\Repository\AccountRepositoryInterface;
use TowerDNS\Application\Repository\AdminImpersonationSessionRepositoryInterface;
use TowerDNS\Application\Repository\UserRepositoryInterface;
use TowerDNS\Application\Services\ActiveAccountService;
use TowerDNS\Application\Services\AuditLogService;
use TowerDNS\Application\Services\AuthorizationService;
use TowerDNS\Domain\Account\AdminImpersonationSession;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Infrastructure\Http\ActiveAccountContext;
use TowerDNS\Infrastructure\Http\ImpersonationContext;
use TowerDNS\Infrastructure\Http\TowerDNSAuthenticatedUser;

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
        private AuthenticationInterface $authentication,
        private AuthorizationService $authorization,
        private ActiveAccountService $activeAccounts,
        private AuditLogService $audit,
    ) {}

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $session = $request->getAttribute(SessionInterface::class);

        if ($session instanceof SessionInterface) {
            $identity = $this->authentication->authenticate($request);

            if ($identity instanceof TowerDNSAuthenticatedUser) {
                $user = $identity->domainUser();
                if ($identity->getIdentity() !== $user->id) {
                    return $handler->handle($request);
                }

                $request       = $request->withAttribute(UserInterface::class, $identity);
                $request       = $request->withAttribute(User::class, $user)->withAttribute('actor_user', $user)->withAttribute(ImpersonationContext::class, new ImpersonationContext($user, $user));
                $switchId      = $session->get('admin_switch_session_id');
                $impersonation = null;
                if (is_string($switchId) && $switchId !== '') {
                    $switch = $this->impersonationSessions->findById($switchId);
                    if (!$switch instanceof AdminImpersonationSession) {
                        return $this->invalidateSwitch($request, $session, $user, $switchId, null, 'missing_session');
                    }
                    if ($switch->effectiveUserId === null) {
                        return $this->invalidateSwitch($request, $session, $user, $switchId, $switch, 'missing_target');
                    }
                    $effectiveUserId = $switch->effectiveUserId;
                    $invalidReason   = match (true) {
                        !$this->authorization->isBuiltInSuperadmin($user)                      => 'actor_privilege_revoked',
                        $switch->actorUserId !== $user->id                                     => 'actor_mismatch',
                        $switch->endedAt     !== null                                          => 'already_ended',
                        new \DateTimeImmutable($switch->expiresAt) <= new \DateTimeImmutable() => 'expired',
                        default                                                                => null,
                    };
                    if ($invalidReason !== null) {
                        return $this->invalidateSwitch($request, $session, $user, $switchId, $switch, $invalidReason);
                    }

                    $effective = $this->users->findById($effectiveUserId);
                    $account   = $switch->effectiveAccountId === null ? null : $this->accounts->findById($switch->effectiveAccountId);

                    // A switched account narrows the effective user's existing scope. It
                    // never grants membership merely because an administrator selected it.
                    if (!$effective instanceof User) {
                        return $this->invalidateSwitch($request, $session, $user, $switchId, $switch, 'target_inactive_or_missing');
                    }
                    if ($switch->effectiveAccountId !== null && (!$account instanceof \TowerDNS\Domain\Account\Account || !$account->isActive || !$this->activeAccounts->resolve($effective, $switch->effectiveAccountId) instanceof \TowerDNS\Domain\Account\Account)) {
                        return $this->invalidateSwitch($request, $session, $user, $switchId, $switch, 'scope_revoked');
                    }

                    $request = $request
                        ->withAttribute(User::class, $effective)
                        ->withAttribute(UserInterface::class, new TowerDNSAuthenticatedUser($effective))
                        ->withAttribute('effective_user', $effective)
                        ->withAttribute('impersonation_session', $switch)
                        ->withAttribute(ImpersonationContext::class, new ImpersonationContext($user, $effective, $switch->effectiveAccountId, $switch));
                    $impersonation = $switch;
                }
                /** @var User $effectiveUser */
                $effectiveUser         = $request->getAttribute(User::class);
                $activeId              = $session->get('active_account_id');
                $impersonatedAccountId = $impersonation instanceof AdminImpersonationSession ? $impersonation->effectiveAccountId : null;
                $active                = $impersonatedAccountId !== null
                    ? $this->activeAccounts->resolve($effectiveUser, $impersonatedAccountId)
                    : (is_int($activeId) || (is_string($activeId) && ctype_digit($activeId))
                        ? $this->activeAccounts->resolve($effectiveUser, (int) $activeId)
                        : $this->activeAccounts->defaultFor($effectiveUser));
                if ($impersonatedAccountId !== null && (int) $activeId !== $impersonatedAccountId) {
                    $session->unset('active_account_id');
                }
                if (!$active instanceof \TowerDNS\Domain\Account\Account && $activeId !== null) {
                    $session->unset('active_account_id');
                    $active = $this->activeAccounts->defaultFor($effectiveUser);
                }
                $request = $request->withAttribute(ActiveAccountContext::class, new ActiveAccountContext($active));
            }
        }

        return $handler->handle($request);
    }

    private function invalidateSwitch(
        ServerRequestInterface $request,
        SessionInterface $session,
        User $actor,
        string $switchId,
        ?AdminImpersonationSession $switch,
        string $reason,
    ): ResponseInterface {
        $session->unset('admin_switch_session_id');
        $belongsToActor = $switch instanceof AdminImpersonationSession && $switch->actorUserId === $actor->id;

        if ($belongsToActor && $switch->endedAt === null) {
            $this->impersonationSessions->end($switchId, new \DateTimeImmutable()->format('Y-m-d H:i:s'));
        }

        $context = AuditLogService::fromHttpRequest(
            $request,
            $actor->id,
            $belongsToActor && $switch->effectiveUserId !== null ? $switch->effectiveUserId : $actor->id,
            impersonationSessionId: $switchId,
        );
        $this->audit->recordWithContext(
            $context,
            'admin.switch.invalidated',
            'impersonation_session',
            $switchId,
            $belongsToActor ? ['effective_user_id' => $switch->effectiveUserId, 'effective_account_id' => $switch->effectiveAccountId] : null,
            null,
            ['reason' => $reason],
        );

        // The invalidating request must not continue under the original actor.
        return new \Laminas\Diactoros\Response\EmptyResponse(403);
    }
}

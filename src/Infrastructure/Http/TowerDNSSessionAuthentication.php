<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http;

use Laminas\Diactoros\Response\EmptyResponse;
use Mezzio\Authentication\AuthenticationInterface;
use Mezzio\Authentication\UserInterface;
use Mezzio\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TowerDNS\Application\Repository\UserRepositoryInterface;

/**
 * Adapts TowerDNS's MFA-aware, rotating session lifecycle to Mezzio's
 * standard authentication contract. The database remains authoritative for
 * account activity and current roles on every request.
 */
final readonly class TowerDNSSessionAuthentication implements AuthenticationInterface
{
    public function __construct(
        private UserRepositoryInterface $users,
        private SessionSecurity $sessionSecurity,
    ) {}

    public function authenticate(ServerRequestInterface $request): ?UserInterface
    {
        $session = $request->getAttribute(SessionInterface::class);
        if (!$session instanceof SessionInterface) {
            return null;
        }

        $userId = $this->sessionSecurity->authenticatedUserId($session);
        if ($userId === null) {
            return null;
        }

        $user = $this->users->findById($userId);
        if (!$user instanceof \TowerDNS\Domain\Auth\User) {
            // An inactive or deleted identity must not leave a reusable stale
            // authenticated session behind.
            $this->sessionSecurity->invalidate($session);
            return null;
        }

        return new TowerDNSAuthenticatedUser($user);
    }

    public function unauthorizedResponse(ServerRequestInterface $request): ResponseInterface
    {
        return new EmptyResponse(401);
    }
}

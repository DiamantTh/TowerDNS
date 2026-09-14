<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

use Laminas\Diactoros\Response\RedirectResponse;
use Mezzio\Csrf\CsrfGuardInterface;
use Mezzio\Csrf\CsrfMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Application\Exception\AuthorizationException;
use TowerDNS\Application\Repository\UserRepositoryInterface;
use TowerDNS\Application\Services\AuthorizationService;
use TowerDNS\Application\Services\IamAdministrationService;
use TowerDNS\Domain\Auth\Permission;
use TowerDNS\Domain\Auth\User;

/**
 * POST /users/{id}/delete — löscht einen Benutzer.
 *
 * Verhindert Selbst-Löschung des angemeldeten Benutzers.
 */
final readonly class UserDeleteHandler implements RequestHandlerInterface
{
    public function __construct(
        private UserRepositoryInterface $users,
        private AuthorizationService    $authz,
        private IamAdministrationService $iam,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var User $currentUser */
        $currentUser = $request->getAttribute(User::class);

        /** @var CsrfGuardInterface $guard */
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        /** @var array<string, string> $body */
        $body  = (array) ($request->getParsedBody() ?? []);
        $token = (string) ($body['csrf_token'] ?? '');

        if (!$guard->validateToken($token)) {
            return new RedirectResponse('/users?error=' . rawurlencode('Ungültige Anfrage.'));
        }

        try {
            $this->authz->assert($currentUser, Permission::USER_MANAGE);
        } catch (AuthorizationException $e) {
            return new RedirectResponse('/users?error=' . rawurlencode($e->getMessage()));
        }

        $targetId = (string) $request->getAttribute('id', '');

        if ($targetId === '' || $targetId === $currentUser->id) {
            return new RedirectResponse('/users?error=' . rawurlencode('Eigenen Account kann man nicht löschen.'));
        }

        $target = $this->users->findById($targetId);
        if (!$target instanceof User) {
            return new RedirectResponse('/users?error=' . rawurlencode('Benutzer nicht gefunden.'));
        }

        try {
            $this->iam->assertCanDelete($targetId);
            $this->users->delete($targetId);
        } catch (\Throwable) {
            return new RedirectResponse('/users?error=' . rawurlencode('User could not be deleted.'));
        }

        return new RedirectResponse('/users?success=' . rawurlencode('Benutzer gelöscht: ' . $target->email));
    }
}

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
use TowerDNS\Application\Repository\RoleRepositoryInterface;
use TowerDNS\Application\Services\AuthorizationService;
use TowerDNS\Domain\Auth\Permission;
use TowerDNS\Domain\Auth\User;

/**
 * POST /roles/{id}/delete — Rolle löschen.
 *
 * Systemrollen können nicht gelöscht werden; der Repository wirft eine
 * DomainException, die hier in eine Fehlermeldung umgewandelt wird.
 */
final class RoleDeleteHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly RoleRepositoryInterface $roles,
        private readonly AuthorizationService    $authz,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var User $currentUser */
        $currentUser = $request->getAttribute(User::class);

        /** @var CsrfGuardInterface $guard */
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);

        /** @var array<string, mixed> $body */
        $body = (array) $request->getParsedBody();

        $raw   = $body['csrf_token'] ?? '';
        $token = is_array($raw) ? (string) ($raw[0] ?? '') : (string) $raw;

        if (!$guard->validateToken($token)) {
            return new RedirectResponse('/roles?error=' . rawurlencode('Ungültiger CSRF-Token.'));
        }

        try {
            $this->authz->assert($currentUser, Permission::ROLE_MANAGE);
        } catch (AuthorizationException $e) {
            return new RedirectResponse('/roles?error=' . rawurlencode($e->getMessage()));
        }

        $roleId = (string) $request->getAttribute('id', '');

        try {
            $this->roles->delete($roleId);
        } catch (\DomainException $e) {
            return new RedirectResponse('/roles?error=' . rawurlencode($e->getMessage()));
        }

        return new RedirectResponse('/roles?success=' . rawurlencode('Rolle wurde gelöscht.'));
    }
}

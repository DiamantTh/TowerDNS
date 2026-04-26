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
use Symfony\Component\Uid\Uuid;
use TowerDNS\Application\Exception\AuthorizationException;
use TowerDNS\Application\Repository\RoleRepositoryInterface;
use TowerDNS\Application\Services\AuthorizationService;
use TowerDNS\Domain\Auth\Permission;
use TowerDNS\Domain\Auth\Role;
use TowerDNS\Domain\Auth\User;

/**
 * POST /roles — Neue Rolle anlegen.
 */
final class RoleCreateHandler implements RequestHandlerInterface
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

        $name = trim(is_string($body['name'] ?? null) ? (string) $body['name'] : '');

        if ($name === '') {
            return new RedirectResponse('/roles?error=' . rawurlencode('Name darf nicht leer sein.'));
        }

        $rawPerms = $body['permissions'] ?? [];
        $rawPerms = is_array($rawPerms) ? $rawPerms : [$rawPerms];

        $permissions = [];
        foreach ($rawPerms as $pv) {
            $perm = Permission::tryFrom((string) $pv);
            if ($perm !== null) {
                $permissions[] = $perm;
            }
        }

        $role = new Role(
            id:          Uuid::v4()->toRfc4122(),
            name:        $name,
            permissions: $permissions,
        );

        $this->roles->save($role);

        return new RedirectResponse('/roles?success=' . rawurlencode('Rolle "' . $name . '" wurde angelegt.'));
    }
}

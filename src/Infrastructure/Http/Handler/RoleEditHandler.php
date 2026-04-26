<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

use Laminas\Diactoros\Response\HtmlResponse;
use Mezzio\Csrf\CsrfGuardInterface;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Template\TemplateRendererInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Application\Exception\AuthorizationException;
use TowerDNS\Application\Repository\RoleRepositoryInterface;
use TowerDNS\Application\Services\AuthorizationService;
use TowerDNS\Domain\Auth\Permission;
use TowerDNS\Domain\Auth\Role;
use TowerDNS\Domain\Auth\User;

/**
 * GET+POST /roles/{id} — Rolle bearbeiten.
 *
 * Systemrollen werden schreibgeschützt angezeigt (isSystem = true).
 */
final readonly class RoleEditHandler implements RequestHandlerInterface
{
    public function __construct(
        private TemplateRendererInterface $renderer,
        private RoleRepositoryInterface   $roles,
        private AuthorizationService      $authz,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var User $currentUser */
        $currentUser = $request->getAttribute(User::class);

        /** @var CsrfGuardInterface $guard */
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);

        $roleId = (string) $request->getAttribute('id', '');

        try {
            $this->authz->assert($currentUser, Permission::ROLE_MANAGE);
        } catch (AuthorizationException $e) {
            return new HtmlResponse(
                $this->renderer->render('app::iam/role_edit', [
                    'user'        => $currentUser,
                    'role'        => null,
                    'permissions' => Permission::cases(),
                    'csrfToken'   => $guard->generateToken(),
                    'error'       => $e->getMessage(),
                    'success'     => null,
                ]),
                403,
            );
        }

        $role = $this->roles->findById($roleId);

        if (!$role instanceof Role) {
            return new HtmlResponse(
                $this->renderer->render('app::iam/role_edit', [
                    'user'        => $currentUser,
                    'role'        => null,
                    'permissions' => Permission::cases(),
                    'csrfToken'   => $guard->generateToken(),
                    'error'       => 'Rolle nicht gefunden.',
                    'success'     => null,
                ]),
                404,
            );
        }

        if ($request->getMethod() === 'GET') {
            return $this->renderForm($currentUser, $role, $guard->generateToken());
        }

        return $this->handlePost($currentUser, $role, $guard, $request);
    }

    private function handlePost(User $currentUser, Role $role, CsrfGuardInterface $guard, ServerRequestInterface $request): ResponseInterface
    {
        /** @var array<string, mixed> $body */
        $body = (array) $request->getParsedBody();

        $raw   = $body['csrf_token'] ?? '';
        $token = is_array($raw) ? (string) ($raw[0] ?? '') : (string) $raw;

        if (!$guard->validateToken($token)) {
            return $this->renderForm($currentUser, $role, $guard->generateToken(), 'Ungültiger CSRF-Token.');
        }

        if ($role->isSystem) {
            return $this->renderForm($currentUser, $role, $guard->generateToken(), 'Systemrollen können nicht bearbeitet werden.');
        }

        $name = trim(is_string($body['name'] ?? null) ? $body['name'] : '');

        if ($name === '') {
            return $this->renderForm($currentUser, $role, $guard->generateToken(), 'Name darf nicht leer sein.');
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

        $updated = new Role(
            id: $role->id,
            name: $name,
            permissions: $permissions,
        );

        $this->roles->save($updated);

        return $this->renderForm($currentUser, $updated, $guard->generateToken(), null, 'Rolle wurde gespeichert.');
    }

    private function renderForm(User $currentUser, ?Role $role, string $csrfToken, ?string $error = null, ?string $success = null): ResponseInterface
    {
        return new HtmlResponse(
            $this->renderer->render('app::iam/role_edit', [
                'user'        => $currentUser,
                'role'        => $role,
                'permissions' => Permission::cases(),
                'csrfToken'   => $csrfToken,
                'error'       => $error,
                'success'     => $success,
            ]),
        );
    }
}

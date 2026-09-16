<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\I18n\Translator\TranslatorInterface;
use Mezzio\Csrf\CsrfGuardInterface;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Template\TemplateRendererInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Application\Auth\ActionGroupRegistry;
use TowerDNS\Application\Exception\AuthorizationException;
use TowerDNS\Application\Repository\RoleRepositoryInterface;
use TowerDNS\Application\Services\AuthorizationService;
use TowerDNS\Domain\Auth\Permission;
use TowerDNS\Domain\Auth\PermissionRegistry;
use TowerDNS\Domain\Auth\Role;
use TowerDNS\Domain\Auth\User;

/**
 * GET+POST /roles/{id} — Rolle bearbeiten.
 *
 * Eingebaute Rollen werden schreibgeschützt angezeigt.
 */
final readonly class RoleEditHandler implements RequestHandlerInterface
{
    public function __construct(
        private TemplateRendererInterface $renderer,
        private RoleRepositoryInterface   $roles,
        private AuthorizationService      $authz,
        private PermissionRegistry        $permissions,
        private ActionGroupRegistry       $actionGroups,
        private TranslatorInterface       $translator,
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
        } catch (AuthorizationException) {
            return new HtmlResponse(
                $this->renderer->render('app::iam/role_edit', [
                    'user' => $currentUser,
                    'role' => null,
                    ...$this->editorData(),
                    'csrfToken' => $guard->generateToken(),
                    'error'     => $this->t('http.error.forbidden'),
                    'success'   => null,
                ]),
                403,
            );
        }

        $role = $this->roles->findById($roleId);

        if (!$role instanceof Role) {
            return new HtmlResponse(
                $this->renderer->render('app::iam/role_edit', [
                    'user' => $currentUser,
                    'role' => null,
                    ...$this->editorData(),
                    'csrfToken' => $guard->generateToken(),
                    'error'     => $this->t('roles.error.not-found'),
                    'success'   => null,
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
            return $this->renderForm($currentUser, $role, $guard->generateToken(), $this->t('roles.error.invalid-csrf'));
        }

        if ($role->isBuiltIn) {
            return $this->renderForm($currentUser, $role, $guard->generateToken(), $this->t('roles.error.built-in-read-only'));
        }

        $name = trim(is_string($body['name'] ?? null) ? $body['name'] : '');

        if ($name === '') {
            return $this->renderForm($currentUser, $role, $guard->generateToken(), $this->t('roles.error.name-required'));
        }

        $rawPerms = $body['permissions'] ?? [];
        $rawPerms = is_array($rawPerms) ? $rawPerms : [$rawPerms];

        $permissions = [];
        foreach ($rawPerms as $pv) {
            try {
                $permissions[] = $this->permissions->assertKnown((string) $pv);
            } catch (\InvalidArgumentException) {
                return $this->renderForm($currentUser, $role, $guard->generateToken(), $this->t('roles.error.invalid-permission'));
            }
        }

        $updated = new Role(
            id: $role->id,
            name: $name,
            permissions: $permissions,
        );

        $this->roles->save($updated);

        return $this->renderForm($currentUser, $updated, $guard->generateToken(), null, $this->t('roles.success.saved'));
    }

    private function renderForm(User $currentUser, ?Role $role, string $csrfToken, ?string $error = null, ?string $success = null): ResponseInterface
    {
        return new HtmlResponse(
            $this->renderer->render('app::iam/role_edit', [
                'user' => $currentUser,
                'role' => $role,
                ...$this->editorData(),
                'csrfToken' => $csrfToken,
                'error'     => $error,
                'success'   => $success,
            ]),
        );
    }

    /** @return array{permissionDefinitions: list<\TowerDNS\Domain\Auth\PermissionDefinition>, actionGroups: list<\TowerDNS\Application\Auth\ActionGroupDefinition>} */
    private function editorData(): array
    {
        return [
            'permissionDefinitions' => $this->permissions->all(),
            'actionGroups'          => $this->actionGroups->all(),
        ];
    }

    private function t(string $key): string
    {
        return $this->translator->translate($key);
    }
}

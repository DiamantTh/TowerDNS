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
use TowerDNS\Domain\Auth\User;

/**
 * GET /roles — Rollenübersicht mit Anlegen-Formular.
 */
final class RoleListHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly TemplateRendererInterface $renderer,
        private readonly RoleRepositoryInterface   $roles,
        private readonly AuthorizationService      $authz,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var User $currentUser */
        $currentUser = $request->getAttribute(User::class);

        /** @var CsrfGuardInterface $guard */
        $guard     = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $csrfToken = $guard->generateToken();

        $flashError   = $request->getQueryParams()['error']   ?? null;
        $flashSuccess = $request->getQueryParams()['success'] ?? null;

        try {
            $this->authz->assert($currentUser, Permission::ROLE_MANAGE);
        } catch (AuthorizationException $e) {
            return new HtmlResponse(
                $this->renderer->render('app::iam/roles', [
                    'user'        => $currentUser,
                    'roles'       => [],
                    'permissions' => Permission::cases(),
                    'csrfToken'   => $csrfToken,
                    'error'       => $e->getMessage(),
                    'success'     => null,
                ]),
                403,
            );
        }

        $allRoles = $this->roles->findAll();

        return new HtmlResponse(
            $this->renderer->render('app::iam/roles', [
                'user'        => $currentUser,
                'roles'       => $allRoles,
                'permissions' => Permission::cases(),
                'csrfToken'   => $csrfToken,
                'error'       => is_string($flashError)   ? $flashError   : null,
                'success'     => is_string($flashSuccess) ? $flashSuccess : null,
            ]),
        );
    }
}

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
use TowerDNS\Application\Exception\AuthorizationException;
use TowerDNS\Application\Repository\RoleRepositoryInterface;
use TowerDNS\Application\Repository\UserRepositoryInterface;
use TowerDNS\Application\Services\AuthorizationService;
use TowerDNS\Domain\Auth\Permission;
use TowerDNS\Domain\Auth\User;

/**
 * GET /users — Benutzerübersicht mit Anlegen-Formular.
 */
final readonly class UserListHandler implements RequestHandlerInterface
{
    public function __construct(
        private TemplateRendererInterface $renderer,
        private UserRepositoryInterface   $users,
        private RoleRepositoryInterface   $roles,
        private AuthorizationService      $authz,
        private TranslatorInterface       $translator,
    ) {}

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
            $this->authz->assert($currentUser, Permission::USER_MANAGE);
        } catch (AuthorizationException) {
            return new HtmlResponse(
                $this->renderer->render('app::iam/users', [
                    'currentUser' => $currentUser,
                    'users'       => [],
                    'roles'       => [],
                    'csrfToken'   => $csrfToken,
                    'error'       => $this->translator->translate('http.error.forbidden'),
                    'success'     => null,
                ]),
                403,
            );
        }

        $allUsers = $this->users->findAll();
        $allRoles = $this->roles->findAll();

        return new HtmlResponse(
            $this->renderer->render('app::iam/users', [
                'currentUser' => $currentUser,
                'users'       => $allUsers,
                'roles'       => $allRoles,
                'csrfToken'   => $csrfToken,
                'error'       => is_string($flashError) ? $flashError : null,
                'success'     => is_string($flashSuccess) ? $flashSuccess : null,
            ]),
        );
    }
}

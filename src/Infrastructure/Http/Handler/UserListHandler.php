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
use TowerDNS\Domain\Account\TeamRole;

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

        $query    = $request->getQueryParams();
        $search   = is_string($query['q'] ?? null) ? trim($query['q']) : null;
        $status   = (string) ($query['status'] ?? 'active');
        $active   = $status === 'all' ? null : $status !== 'inactive';
        $account  = is_string($query['account'] ?? null) ? trim((string) $query['account']) : null;
        $membershipRole = TeamRole::tryFrom((string) ($query['membership_role'] ?? ''));
        $page     = max(1, (int) ($query['page'] ?? 1));
        $pageSize = 50;
        $loaded   = $this->users->findAll($search, $active, $pageSize + 1, ($page - 1) * $pageSize, $account, $membershipRole);
        $hasNext  = count($loaded) > $pageSize;
        $allUsers = array_slice($loaded, 0, $pageSize);
        $allRoles = $this->roles->findAll();

        return new HtmlResponse(
            $this->renderer->render('app::iam/users', [
                'currentUser' => $currentUser,
                'users'       => $allUsers,
                'roles'       => $allRoles,
                'csrfToken'   => $csrfToken,
                'error'       => is_string($flashError) ? $flashError : null,
                'success'     => is_string($flashSuccess) ? $flashSuccess : null,
                'search'      => $search ?? '',
                'status'      => $status,
                'account'     => $account ?? '',
                'membershipRole' => $membershipRole?->value ?: 'all',
                'membershipRoles' => TeamRole::cases(),
                'page'        => $page,
                'hasNext'     => $hasNext,
            ]),
        );
    }
}

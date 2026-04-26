<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
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
 * GET  /users/{id} — Benutzerdetails + Rollenzuweisung-Formular.
 * POST /users/{id} — syncRoles() für den Benutzer ausführen.
 */
final class UserEditHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly TemplateRendererInterface $renderer,
        private readonly UserRepositoryInterface   $users,
        private readonly RoleRepositoryInterface   $roles,
        private readonly AuthorizationService      $authz,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var User $currentUser */
        $currentUser = $request->getAttribute(User::class);

        $targetId = (string) $request->getAttribute('id', '');

        /** @var CsrfGuardInterface $guard */
        $guard     = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $csrfToken = $guard->generateToken();

        try {
            $this->authz->assert($currentUser, Permission::USER_MANAGE);
        } catch (AuthorizationException $e) {
            return new HtmlResponse(
                $this->renderer->render('app::iam/user_edit', [
                    'currentUser' => $currentUser,
                    'target'      => null,
                    'allRoles'    => [],
                    'csrfToken'   => $csrfToken,
                    'error'       => $e->getMessage(),
                    'success'     => null,
                ]),
                403,
            );
        }

        $target = $this->users->findById($targetId);
        if ($target === null) {
            return new HtmlResponse(
                $this->renderer->render('app::iam/user_edit', [
                    'currentUser' => $currentUser,
                    'target'      => null,
                    'allRoles'    => [],
                    'csrfToken'   => $csrfToken,
                    'error'       => 'Benutzer nicht gefunden.',
                    'success'     => null,
                ]),
                404,
            );
        }

        $allRoles = $this->roles->findAll();

        if ($request->getMethod() === 'GET') {
            return new HtmlResponse(
                $this->renderer->render('app::iam/user_edit', [
                    'currentUser' => $currentUser,
                    'target'      => $target,
                    'allRoles'    => $allRoles,
                    'csrfToken'   => $csrfToken,
                    'error'       => null,
                    'success'     => null,
                ]),
            );
        }

        // POST — sync roles
        /** @var array<string, mixed> $body */
        $body  = (array) ($request->getParsedBody() ?? []);
        $raw   = $body['csrf_token'] ?? '';
        $token = is_array($raw) ? (string) ($raw[0] ?? '') : (string) $raw;

        if (!$guard->validateToken($token)) {
            return new RedirectResponse('/users/' . rawurlencode($targetId) . '?error=' . rawurlencode('Ungültige Anfrage.'));
        }

        /** @var list<string> $selectedRoles */
        $selectedRoles = [];
        if (isset($body['roles']) && is_array($body['roles'])) {
            foreach ($body['roles'] as $r) {
                if (is_string($r) && $r !== '') {
                    $selectedRoles[] = $r;
                }
            }
        }

        try {
            $this->users->syncRoles($targetId, $selectedRoles);
        } catch (\Throwable $e) {
            return new HtmlResponse(
                $this->renderer->render('app::iam/user_edit', [
                    'currentUser' => $currentUser,
                    'target'      => $target,
                    'allRoles'    => $allRoles,
                    'csrfToken'   => $guard->generateToken(),
                    'error'       => 'Fehler beim Speichern der Rollen: ' . $e->getMessage(),
                    'success'     => null,
                ]),
                500,
            );
        }

        // Reload user so the template reflects the updated roles.
        $updated = $this->users->findById($targetId) ?? $target;

        return new HtmlResponse(
            $this->renderer->render('app::iam/user_edit', [
                'currentUser' => $currentUser,
                'target'      => $updated,
                'allRoles'    => $allRoles,
                'csrfToken'   => $guard->generateToken(),
                'error'       => null,
                'success'     => 'Rollen wurden gespeichert.',
            ]),
        );
    }
}

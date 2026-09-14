<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
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
use TowerDNS\Application\Services\AuditLogService;
use TowerDNS\Application\Services\AuthorizationService;
use TowerDNS\Application\Services\IamAdministrationService;
use TowerDNS\Application\Services\PasswordAdministrationService;
use TowerDNS\Domain\Auth\Permission;
use TowerDNS\Domain\Auth\User;

/**
 * GET  /users/{id} — Benutzerdetails + Rollenzuweisung-Formular.
 * POST /users/{id} — syncRoles() für den Benutzer ausführen.
 */
final readonly class UserEditHandler implements RequestHandlerInterface
{
    public function __construct(
        private TemplateRendererInterface $renderer,
        private UserRepositoryInterface   $users,
        private RoleRepositoryInterface   $roles,
        private AuthorizationService      $authz,
        private IamAdministrationService  $iam,
        private PasswordAdministrationService $passwords,
        private AuditLogService           $audit,
        private TranslatorInterface       $translator,
    ) {}

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
        } catch (AuthorizationException) {
            return new HtmlResponse(
                $this->renderer->render('app::iam/user_edit', [
                    'currentUser' => $currentUser,
                    'target'      => null,
                    'allRoles'    => [],
                    'csrfToken'   => $csrfToken,
                    'error'       => $this->translator->translate('http.error.forbidden'),
                    'success'     => null,
                ]),
                403,
            );
        }

        $target = $this->users->findById($targetId);
        if (!$target instanceof User) {
            return new HtmlResponse(
                $this->renderer->render('app::iam/user_edit', [
                    'currentUser' => $currentUser,
                    'target'      => null,
                    'allRoles'    => [],
                    'csrfToken'   => $csrfToken,
                    'error'       => $this->translator->translate('users.error.not-found'),
                    'success'     => null,
                ]),
                404,
            );
        }

        $allRoles = $this->roles->findAll();

        if ($request->getMethod() === 'GET') {
            $flashError   = $request->getQueryParams()['error']   ?? null;
            $flashSuccess = $request->getQueryParams()['success'] ?? null;

            return new HtmlResponse(
                $this->renderer->render('app::iam/user_edit', [
                    'currentUser' => $currentUser,
                    'target'      => $target,
                    'allRoles'    => $allRoles,
                    'csrfToken'   => $csrfToken,
                    'error'       => $flashError,
                    'success'     => $flashSuccess,
                ]),
            );
        }

        // POST — sync roles / update display name
        /** @var array<string, mixed> $body */
        $body  = (array) ($request->getParsedBody() ?? []);
        $raw   = $body['csrf_token'] ?? '';
        $token = is_array($raw) ? (string) ($raw[0] ?? '') : (string) $raw;

        if (!$guard->validateToken($token)) {
            return new RedirectResponse('/users/' . rawurlencode($targetId) . '?error=' . rawurlencode($this->translator->translate('http.error.invalid-request')));
        }

        $action = (string) ($body['action'] ?? 'roles');

        // ── Anzeigename ────────────────────────────────────────────────────
        if ($action === 'display_name') {
            $displayName = trim((string) ($body['display_name'] ?? ''));
            try {
                $this->users->updateDisplayName($targetId, $displayName);
            } catch (\Throwable) {
                return new RedirectResponse('/users/' . rawurlencode($targetId) . '?error=' . rawurlencode($this->translator->translate('users.error.save-failed')));
            }
            return new RedirectResponse('/users/' . rawurlencode($targetId) . '?success=' . rawurlencode($this->translator->translate('users.success.display-name-updated')));
        }

        // ── Passwort zurücksetzen ─────────────────────────────────────────
        if ($action === 'reset_password') {
            $newPassword = (string) ($body['new_password'] ?? '');
            $keepKeys    = isset($body['keep_api_keys']);

            try {
                $revokedCount = $this->passwords->setByAdministrator($targetId, $newPassword, $keepKeys);
            } catch (\InvalidArgumentException) {
                return new RedirectResponse('/users/' . rawurlencode($targetId) . '?error=' . rawurlencode($this->translator->translate('auth.error.password-policy')));
            } catch (\Throwable) {
                return new RedirectResponse('/users/' . rawurlencode($targetId) . '?error=' . rawurlencode($this->translator->translate('users.error.password-reset-failed')));
            }

            $this->audit->recordPasswordSetByAdministrator($request, $currentUser->id, $targetId, $revokedCount);
            $msg = $this->translator->translate('users.success.password-reset');
            if (!$keepKeys && $revokedCount > 0) {
                $msg .= ' ' . strtr($this->translator->translate('users.success.api-keys-revoked'), ['{count}' => (string) $revokedCount]);
            }
            return new RedirectResponse('/users/' . rawurlencode($targetId) . '?success=' . rawurlencode($msg));
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
            $this->iam->assertCanSyncRoles($targetId, $selectedRoles);
            $this->users->syncRoles($targetId, $selectedRoles);
        } catch (\Throwable) {
            return new HtmlResponse(
                $this->renderer->render('app::iam/user_edit', [
                    'currentUser' => $currentUser,
                    'target'      => $target,
                    'allRoles'    => $allRoles,
                    'csrfToken'   => $guard->generateToken(),
                    'error'       => $this->translator->translate('users.error.roles-save-failed'),
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
                'success'     => $this->translator->translate('users.success.roles-saved'),
            ]),
        );
    }
}

<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Laminas\Translator\TranslatorInterface;
use Mezzio\Csrf\CsrfGuardInterface;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Template\TemplateRendererInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Application\DTO\AuditContext;
use TowerDNS\Application\DTO\StepUpAction;
use TowerDNS\Application\Exception\AuthorizationException;
use TowerDNS\Application\Exception\StepUpRequiredException;
use TowerDNS\Application\Repository\RoleRepositoryInterface;
use TowerDNS\Application\Repository\UserRepositoryInterface;
use TowerDNS\Application\Services\AuditLogService;
use TowerDNS\Application\Services\AuthorizationService;
use TowerDNS\Application\Services\IamAdministrationService;
use TowerDNS\Application\Services\PasswordAdministrationService;
use TowerDNS\Domain\Auth\Permission;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Infrastructure\Http\StepUpRequestService;

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
        private TranslatorInterface       $translator,
        private StepUpRequestService      $stepUpRequests,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var User $currentUser */
        $currentUser = $request->getAttribute(User::class);

        $targetId = (string) $request->getAttribute('id', '');

        /** @var CsrfGuardInterface $guard */
        $guard     = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $csrfToken = $guard->generateToken();
        /** @var array<string, mixed> $body */
        $body   = (array) ($request->getParsedBody() ?? []);
        $action = (string) ($body['action'] ?? 'roles');

        try {
            $this->authz->assert($currentUser, Permission::USER_MANAGE);
        } catch (AuthorizationException) {
            // Valid, CSRF-protected role/status mutations must reach the IAM
            // service so it can enforce and audit the denial at the application
            // boundary. Other user-management actions remain blocked here.
            if ($request->getMethod() !== 'POST' || !in_array($action, ['roles', 'status'], true)) {
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
        }

        $target = $this->users->findByIdForAdministration($targetId);
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
        $raw   = $body['csrf_token'] ?? '';
        $token = is_array($raw) ? (string) ($raw[0] ?? '') : (string) $raw;

        if (!$guard->validateToken($token)) {
            return new RedirectResponse('/users/' . rawurlencode($targetId) . '?error=' . rawurlencode($this->translator->translate('http.error.invalid-request')));
        }

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

        if ($action === 'status') {
            try {
                $active = (string) ($body['active'] ?? '') === '1';
                $proof  = $this->stepUpRequests->consume($request, $currentUser->id, StepUpAction::IAM_USER_STATUS, $targetId);
                $this->iam->setUserActive($currentUser, $targetId, $active, $this->auditContext($request, $currentUser), $proof);
            } catch (StepUpRequiredException $required) {
                return $this->stepUpRequests->challenge($request, $currentUser->id, $required);
            } catch (AuthorizationException) {
                return new RedirectResponse('/users/' . rawurlencode($targetId) . '?error=' . rawurlencode($this->translator->translate('http.error.forbidden')));
            } catch (\Throwable) {
                return new RedirectResponse('/users/' . rawurlencode($targetId) . '?error=' . rawurlencode($this->translator->translate('users.error.save-failed')));
            }
            return new RedirectResponse('/users/' . rawurlencode($targetId) . '?success=' . rawurlencode($this->translator->translate('users.success.status-updated')));
        }

        // ── Passwort zurücksetzen ─────────────────────────────────────────
        if ($action === 'reset_password') {
            if ($request->getAttribute('impersonation_session') !== null) {
                return new RedirectResponse('/users/' . rawurlencode($targetId) . '?error=' . rawurlencode($this->translator->translate('http.error.forbidden')));
            }
            $newPassword = (string) ($body['new_password'] ?? '');
            $keepKeys    = isset($body['keep_api_keys']);

            try {
                $proof        = $this->stepUpRequests->consume($request, $currentUser->id, StepUpAction::IAM_USER_PASSWORD, $targetId);
                $revokedCount = $this->passwords->setByAdministrator(
                    $currentUser,
                    $targetId,
                    $newPassword,
                    $keepKeys,
                    $this->auditContext($request, $currentUser),
                    $proof,
                );
            } catch (StepUpRequiredException $required) {
                return $this->stepUpRequests->challenge($request, $currentUser->id, $required);
            } catch (AuthorizationException) {
                return new RedirectResponse('/users/' . rawurlencode($targetId) . '?error=' . rawurlencode($this->translator->translate('http.error.forbidden')));
            } catch (\InvalidArgumentException) {
                return new RedirectResponse('/users/' . rawurlencode($targetId) . '?error=' . rawurlencode($this->translator->translate('auth.error.password-policy')));
            } catch (\Throwable) {
                return new RedirectResponse('/users/' . rawurlencode($targetId) . '?error=' . rawurlencode($this->translator->translate('users.error.password-reset-failed')));
            }
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
            $proof = $this->stepUpRequests->consume($request, $currentUser->id, StepUpAction::IAM_USER_ROLES, $targetId);
            $this->iam->syncRoles($currentUser, $targetId, $selectedRoles, $this->auditContext($request, $currentUser), $proof);
        } catch (StepUpRequiredException $required) {
            return $this->stepUpRequests->challenge($request, $currentUser->id, $required);
        } catch (AuthorizationException) {
            return new HtmlResponse(
                $this->renderer->render('app::iam/user_edit', [
                    'currentUser' => $currentUser,
                    'target'      => $target,
                    'allRoles'    => $allRoles,
                    'csrfToken'   => $guard->generateToken(),
                    'error'       => $this->translator->translate('http.error.forbidden'),
                    'success'     => null,
                ]),
                403,
            );
        } catch (\DomainException) {
            return new HtmlResponse(
                $this->renderer->render('app::iam/user_edit', [
                    'currentUser' => $currentUser,
                    'target'      => $target,
                    'allRoles'    => $allRoles,
                    'csrfToken'   => $guard->generateToken(),
                    'error'       => $this->translator->translate('users.error.roles-save-failed'),
                    'success'     => null,
                ]),
                409,
            );
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
        $updated = $this->users->findByIdForAdministration($targetId) ?? $target;

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

    private function auditContext(ServerRequestInterface $request, User $effectiveUser): AuditContext
    {
        $original = $request->getAttribute('actor_user');
        $switch   = $request->getAttribute('impersonation_session');
        return AuditLogService::fromHttpRequest(
            $request,
            $original instanceof User ? $original->id : $effectiveUser->id,
            $effectiveUser->id,
            impersonationSessionId: $switch instanceof \TowerDNS\Domain\Account\AdminImpersonationSession ? $switch->id : null,
        );
    }
}

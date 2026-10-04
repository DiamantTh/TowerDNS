<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

use Doctrine\DBAL\Connection;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Laminas\Translator\TranslatorInterface;
use Mezzio\Csrf\CsrfGuardInterface;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Template\TemplateRendererInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Application\DTO\StepUpAction;
use TowerDNS\Application\Repository\UserRepositoryInterface;
use TowerDNS\Application\Repository\WebAuthnCredentialRepositoryInterface;
use TowerDNS\Application\Services\AuditLogService;
use TowerDNS\Application\Services\AuthenticationPathPolicy;
use TowerDNS\Application\Services\PasswordPolicy;
use TowerDNS\Domain\Account\AdminImpersonationSession;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Infrastructure\Http\FormInput;
use TowerDNS\Infrastructure\Http\StepUpRequestService;
use TowerDNS\Infrastructure\Persistence\PlatformDetector;

/** Changes or disables the optional password break-glass factor. */
final readonly class PasswordChangeHandler implements RequestHandlerInterface
{
    /** @psalm-suppress PossiblyUnusedMethod Resolved by the route container from the handler class name. */
    public function __construct(
        private Connection $connection,
        private TemplateRendererInterface $renderer,
        private UserRepositoryInterface $users,
        private PasswordPolicy $policy,
        private TranslatorInterface $translator,
        private StepUpRequestService $stepUp,
        private WebAuthnCredentialRepositoryInterface $webAuthnCredentials,
        private AuditLogService $audit,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var User $user */
        $user = $request->getAttribute(User::class);
        /** @var CsrfGuardInterface $guard */
        $guard              = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $passwordConfigured = $this->users->fetchPasswordHash($user->email) !== null;

        if ($request->getMethod() === 'GET') {
            $success = isset($request->getQueryParams()['success'])
                ? $this->translator->translate('auth.success.password-changed')
                : null;
            return $this->renderForm($user, $guard->generateToken(), null, $success, $passwordConfigured);
        }

        if ($request->getAttribute('impersonation_session') instanceof AdminImpersonationSession) {
            return $this->renderForm($user, $guard->generateToken(), $this->translator->translate('http.error.forbidden'), null, $passwordConfigured, 403);
        }

        $body = FormInput::fromParsedBody($request->getParsedBody());
        if (!$guard->validateToken($body['csrf_token'] ?? '')) {
            return $this->renderForm($user, $guard->generateToken(), $this->translator->translate('roles.error.invalid-csrf'), null, $passwordConfigured, 400);
        }

        $action = $body['action'] ?? 'change';
        if ($action === 'disable') {
            return $this->disable($request, $user, $guard, $passwordConfigured);
        }

        if (!$this->stepUp->hasProof($request, $user->id, StepUpAction::PROFILE_PASSWORD_CHANGE, $user->id)) {
            return $this->stepUp->challengeAction($request, $user->id, StepUpAction::PROFILE_PASSWORD_CHANGE, $user->id);
        }

        $newPassword = $body['new_password']         ?? '';
        $confirm     = $body['new_password_confirm'] ?? '';
        if ($newPassword === '' || $newPassword !== $confirm) {
            $this->wipePasswordInput($body);
            sodium_memzero($newPassword);
            sodium_memzero($confirm);
            return $this->renderForm($user, $guard->generateToken(), $this->translator->translate('auth.error.passwords-do-not-match'), null, $passwordConfigured, 422);
        }
        try {
            $this->policy->assertValid($newPassword);
        } catch (\InvalidArgumentException) {
            $this->wipePasswordInput($body);
            sodium_memzero($newPassword);
            sodium_memzero($confirm);
            return $this->renderForm($user, $guard->generateToken(), $this->translator->translate('auth.error.password-policy'), null, $passwordConfigured, 422);
        }

        $hash = password_hash($newPassword, PASSWORD_ARGON2ID, ['memory_cost' => 131072, 'time_cost' => 4, 'threads' => 4]);
        $this->wipePasswordInput($body);
        sodium_memzero($newPassword);
        sodium_memzero($confirm);
        $proof = $this->stepUp->consume($request, $user->id, StepUpAction::PROFILE_PASSWORD_CHANGE, $user->id);
        if (!$proof instanceof \TowerDNS\Application\DTO\StepUpProof) {
            return $this->stepUp->challengeAction($request, $user->id, StepUpAction::PROFILE_PASSWORD_CHANGE, $user->id);
        }
        $this->users->updatePasswordHash($user->id, $hash);
        $this->audit->record($request, $passwordConfigured ? 'user.password.changed' : 'user.password.enabled', 'user', $user->id, $user->id, null, null, null, null, $user->id, null, null, ['step_up_method' => $proof->method]);

        return new RedirectResponse('/profile/password?success=1');
    }

    private function disable(ServerRequestInterface $request, User $user, CsrfGuardInterface $guard, bool $passwordConfigured): ResponseInterface
    {
        if (!$passwordConfigured) {
            return $this->renderForm($user, $guard->generateToken(), $this->translator->translate('password.error.not-configured'), null, false, 409);
        }
        if (!$this->stepUp->hasProof($request, $user->id, StepUpAction::PROFILE_PASSWORD_DISABLE, $user->id)) {
            return $this->stepUp->challengeAction($request, $user->id, StepUpAction::PROFILE_PASSWORD_DISABLE, $user->id);
        }

        $result = $this->connection->transactional(function (Connection $connection) use ($request, $user): string {
            if (!PlatformDetector::isSqlite($connection)) {
                $connection->fetchOne('SELECT id FROM users WHERE id = ? FOR UPDATE', [$user->id]);
            }
            if ($this->users->fetchPasswordHash($user->email) === null) {
                return 'not-configured';
            }
            if (!AuthenticationPathPolicy::permitsPasswordDisable($this->webAuthnCredentials->countByUserId($user->id))) {
                return 'passkey-required';
            }
            $proof = $this->stepUp->consume($request, $user->id, StepUpAction::PROFILE_PASSWORD_DISABLE, $user->id);
            if (!$proof instanceof \TowerDNS\Application\DTO\StepUpProof) {
                return 'step-up';
            }

            // An empty, non-hash value represents the absent optional password in
            // older databases whose column was installed as NOT NULL.
            $this->users->updatePasswordHash($user->id, '');
            $this->audit->record($request, 'user.password.disabled', 'user', $user->id, $user->id, null, null, null, null, $user->id, null, null, ['step_up_method' => $proof->method]);

            return 'disabled';
        });
        if ($result === 'not-configured') {
            return $this->renderForm($user, $guard->generateToken(), $this->translator->translate('password.error.not-configured'), null, false, 409);
        }
        if ($result === 'passkey-required') {
            return $this->renderForm($user, $guard->generateToken(), $this->translator->translate('password.error.passkey-required'), null, true, 409);
        }
        if ($result === 'step-up') {
            return $this->stepUp->challengeAction($request, $user->id, StepUpAction::PROFILE_PASSWORD_DISABLE, $user->id);
        }

        return new RedirectResponse('/profile/password?success=1');
    }

    private function renderForm(User $user, string $csrfToken, ?string $error = null, ?string $success = null, bool $passwordConfigured = true, int $status = 200): ResponseInterface
    {
        return new HtmlResponse($this->renderer->render('app::profile/password', [
            'user'               => $user,
            'csrfToken'          => $csrfToken,
            'error'              => $error,
            'success'            => $success,
            'passwordConfigured' => $passwordConfigured,
            'active'             => 'profile',
        ]), $status);
    }

    /** @param array<string, string|null> $body */
    private function wipePasswordInput(array &$body): void
    {
        foreach (['new_password', 'new_password_confirm'] as $key) {
            if (isset($body[$key])) {
                sodium_memzero($body[$key]);
            }
        }
    }
}

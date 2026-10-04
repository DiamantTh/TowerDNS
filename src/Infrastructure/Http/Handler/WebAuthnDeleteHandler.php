<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

use Doctrine\DBAL\Connection;
use Laminas\Diactoros\Response\RedirectResponse;
use Laminas\Translator\TranslatorInterface;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Router\RouteResult;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Application\DTO\StepUpAction;
use TowerDNS\Application\Repository\UserRepositoryInterface;
use TowerDNS\Application\Repository\WebAuthnCredentialRepositoryInterface;
use TowerDNS\Application\Services\AuditLogService;
use TowerDNS\Application\Services\AuthenticationPathPolicy;
use TowerDNS\Application\Services\TotpSecretService;
use TowerDNS\Domain\Account\AdminImpersonationSession;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Infrastructure\Http\FormInput;
use TowerDNS\Infrastructure\Http\StepUpRequestService;
use TowerDNS\Infrastructure\Persistence\PlatformDetector;

/** Deletes an owned WebAuthn credential after target-bound step-up verification. */
final readonly class WebAuthnDeleteHandler implements RequestHandlerInterface
{
    /** @psalm-suppress PossiblyUnusedMethod Resolved by the route container from the handler class name. */
    public function __construct(
        private Connection $connection,
        private WebAuthnCredentialRepositoryInterface $credentialRepo,
        private UserRepositoryInterface $users,
        private TotpSecretService $totpSecrets,
        private StepUpRequestService $stepUp,
        private AuditLogService $audit,
        private TranslatorInterface $translator,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var \Mezzio\Csrf\CsrfGuardInterface $guard */
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $body  = FormInput::fromParsedBody($request->getParsedBody());
        if (!$guard->validateToken($body['csrf_token'] ?? '')) {
            return new RedirectResponse('/profile/webauthn?error=' . rawurlencode($this->translator->translate('http.error.invalid-request')));
        }
        /** @var array<string, string> $routeParams */
        $routeParams  = $request->getAttribute(RouteResult::class)?->getMatchedParams() ?? [];
        $encodedId    = $routeParams['credentialId']                                    ?? '';
        $credentialId = $encodedId !== '' ? base64_decode(strtr($encodedId, '-_', '+/'), true) : false;
        /** @var User $currentUser */
        $currentUser = $request->getAttribute(User::class);
        if (!is_string($credentialId) || $credentialId === ''
                                      || !($this->credentialRepo->findByCredentialIdForUser($credentialId, $currentUser->id) instanceof \Webauthn\CredentialRecord)) {
            return new RedirectResponse('/profile/webauthn?error=' . rawurlencode($this->translator->translate('webauthn.error.key-not-found')));
        }
        if ($request->getAttribute('impersonation_session') instanceof AdminImpersonationSession) {
            return new RedirectResponse('/profile/webauthn?error=' . rawurlencode($this->translator->translate('http.error.forbidden')));
        }

        $stepUpTarget = hash('sha256', $encodedId);
        if (!$this->stepUp->hasProof($request, $currentUser->id, StepUpAction::PROFILE_WEBAUTHN_DELETE, $stepUpTarget)) {
            return $this->stepUp->challengeAction($request, $currentUser->id, StepUpAction::PROFILE_WEBAUTHN_DELETE, $stepUpTarget);
        }

        // Credential removals share the user-row lock with enrollment. This
        // makes the remaining-path check atomic when several security changes
        // race in separate non-locking web sessions.
        $result = $this->connection->transactional(function (Connection $connection) use ($request, $currentUser, $credentialId, $stepUpTarget): string {
            if (!PlatformDetector::isSqlite($connection)) {
                $connection->fetchOne('SELECT id FROM users WHERE id = ? FOR UPDATE', [$currentUser->id]);
            }
            if (!$this->credentialRepo->findByCredentialIdForUser($credentialId, $currentUser->id) instanceof \Webauthn\CredentialRecord) {
                return 'missing';
            }

            $remaining         = $this->credentialRepo->countByUserId($currentUser->id) - 1;
            $passwordAvailable = $this->users->fetchPasswordHash($currentUser->email) !== null;
            $totpAvailable     = $this->totpSecrets->isEnabled($currentUser->id);
            // TOTP cannot be used as a primary login. Removing the final key
            // must retain password + TOTP so MFA cannot silently disappear.
            if (!AuthenticationPathPolicy::permitsWebAuthnRemoval($remaining, $passwordAvailable, $totpAvailable)) {
                return 'last-path';
            }

            $proof = $this->stepUp->consume($request, $currentUser->id, StepUpAction::PROFILE_WEBAUTHN_DELETE, $stepUpTarget);
            if (!$proof instanceof \TowerDNS\Application\DTO\StepUpProof) {
                return 'step-up';
            }
            $this->credentialRepo->delete($credentialId, $currentUser->id);
            $this->audit->record($request, 'user.webauthn.credential.removed', 'user', $currentUser->id, $currentUser->id, null, null, null, null, $currentUser->id, null, null, ['step_up_method' => $proof->method]);

            return 'deleted';
        });

        if ($result === 'missing') {
            return new RedirectResponse('/profile/webauthn?error=' . rawurlencode($this->translator->translate('webauthn.error.key-not-found')));
        }
        if ($result === 'last-path') {
            return new RedirectResponse('/profile/webauthn?error=' . rawurlencode($this->translator->translate('webauthn.error.last-login-path')));
        }
        if ($result === 'step-up') {
            return $this->stepUp->challengeAction($request, $currentUser->id, StepUpAction::PROFILE_WEBAUTHN_DELETE, $stepUpTarget);
        }

        return new RedirectResponse('/profile/webauthn?success=' . rawurlencode($this->translator->translate('webauthn.success.key-removed')));
    }
}

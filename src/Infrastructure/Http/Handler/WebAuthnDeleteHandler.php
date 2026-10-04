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
use TowerDNS\Application\Repository\WebAuthnCredentialRepositoryInterface;
use TowerDNS\Application\Services\AuditLogService;
use TowerDNS\Application\Services\AuthenticationPathPolicy;
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

        $existingCount = $this->credentialRepo->countByUserId($currentUser->id);
        if (!AuthenticationPathPolicy::permitsWebAuthnRemoval($existingCount)) {
            return new RedirectResponse('/profile/webauthn?error=' . rawurlencode($this->translator->translate('webauthn.error.last-key-self-service')));
        }

        $stepUpTarget = hash('sha256', $credentialId);
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

            $credentials   = $this->credentialRepo->findByUserId($currentUser->id);
            $existingCount = count($credentials);
            if (!AuthenticationPathPolicy::permitsWebAuthnRemoval($existingCount)) {
                return 'last-path';
            }

            $proof = $this->stepUp->consume($request, $currentUser->id, StepUpAction::PROFILE_WEBAUTHN_DELETE, $stepUpTarget);
            if (!$proof instanceof \TowerDNS\Application\DTO\StepUpProof) {
                return 'step-up';
            }
            $proofCredentialIdHash = $proof->credentialIdHash;
            if ($proof->method !== 'webauthn' || !is_string($proofCredentialIdHash)) {
                return 'step-up';
            }
            $proofMatchesOwnedCredential = array_any(
                $credentials,
                static fn(array $entry): bool => hash_equals($proofCredentialIdHash, hash('sha256', $entry['source']->publicKeyCredentialId)),
            );
            if (!$proofMatchesOwnedCredential) {
                return 'step-up';
            }
            if ($existingCount === 2 && hash_equals($stepUpTarget, $proofCredentialIdHash)) {
                return 'wrong-key';
            }

            $this->credentialRepo->delete($credentialId, $currentUser->id);
            $this->audit->record($request, 'user.webauthn.credential.removed', 'user', $currentUser->id, $currentUser->id, null, null, null, null, $currentUser->id, null, null, ['step_up_method' => $proof->method]);

            return 'deleted';
        });

        if ($result === 'missing') {
            return new RedirectResponse('/profile/webauthn?error=' . rawurlencode($this->translator->translate('webauthn.error.key-not-found')));
        }
        if ($result === 'last-path') {
            return new RedirectResponse('/profile/webauthn?error=' . rawurlencode($this->translator->translate('webauthn.error.last-key-self-service')));
        }
        if ($result === 'wrong-key') {
            return new RedirectResponse('/profile/webauthn?error=' . rawurlencode($this->translator->translate('webauthn.error.use-another-key')));
        }
        if ($result === 'step-up') {
            return $this->stepUp->challengeAction($request, $currentUser->id, StepUpAction::PROFILE_WEBAUTHN_DELETE, $stepUpTarget);
        }

        return new RedirectResponse('/profile/webauthn?success=' . rawurlencode($this->translator->translate('webauthn.success.key-removed')));
    }
}

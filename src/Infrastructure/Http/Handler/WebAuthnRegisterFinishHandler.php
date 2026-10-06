<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Translator\TranslatorInterface;
use Mezzio\Csrf\CsrfGuardInterface;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Session\SessionIdentifierAwareInterface;
use Mezzio\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Application\DTO\StepUpAction;
use TowerDNS\Application\Exception\WebAuthnCredentialLimitException;
use TowerDNS\Application\Repository\SystemSettingsRepositoryInterface;
use TowerDNS\Application\Repository\WebAuthnCredentialRepositoryInterface;
use TowerDNS\Application\Services\AccountRecoveryService;
use TowerDNS\Application\Services\AuditLogService;
use TowerDNS\Application\Services\WebAuthnService;
use TowerDNS\Domain\Account\AdminImpersonationSession;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Infrastructure\Http\StepUpRequestService;
use Webauthn\Exception\AuthenticatorResponseVerificationException;

/** Validates a one-shot, user-bound WebAuthn registration ceremony. */
final readonly class WebAuthnRegisterFinishHandler implements RequestHandlerInterface
{
    private const int PENDING_TTL_SECONDS = 300;

    /** @psalm-suppress PossiblyUnusedMethod Resolved by the route container from the handler class name. */
    public function __construct(
        private WebAuthnService $webAuthn,
        private WebAuthnCredentialRepositoryInterface $credentialRepo,
        private SystemSettingsRepositoryInterface $settings,
        private StepUpRequestService $stepUp,
        private AuditLogService $audit,
        private TranslatorInterface $translator,
        private ?AccountRecoveryService $recoveryService = null,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $session = $request->getAttribute(SessionInterface::class);
        if (!$session instanceof SessionInterface) {
            return new JsonResponse(['error' => $this->translator->translate('webauthn.error.registration-pending')], 400);
        }
        /** @var CsrfGuardInterface $guard */
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $body  = (string) $request->getBody();
        /** @var array<string, mixed>|null $parsed */
        $parsed = json_decode($body, true);
        if (!is_array($parsed) || !$guard->validateToken(is_string($parsed['csrf_token'] ?? null) ? $parsed['csrf_token'] : '')) {
            return new JsonResponse(['error' => $this->translator->translate('http.error.invalid-request')], 400);
        }

        $pending = $session->get('webauthn_register_pending');
        // Consume the pending ceremony before any response parsing.
        $session->unset('webauthn_register_pending');
        /** @var User $currentUser */
        $currentUser = $request->getAttribute(User::class);
        $now         = time();
        if (!is_array($pending)
            || !is_string($pending['user_id'] ?? null)
            || $pending['user_id'] !== $currentUser->id
            || !is_string($pending['options'] ?? null)
            || !is_string($pending['label'] ?? null)
            || !is_string($pending['purpose'] ?? null)
            || !is_int($pending['created_at'] ?? null)
            || !is_int($pending['expires_at'] ?? null)
            || $pending['created_at'] > $now
            || $pending['expires_at'] < $now
            || $pending['expires_at'] - $pending['created_at'] > self::PENDING_TTL_SECONDS) {
            $this->auditRecoveryFailure($request, $currentUser, 'pending_invalid');
            return new JsonResponse(['error' => $this->translator->translate('webauthn.error.registration-pending')], 400);
        }
        if ($request->getAttribute('impersonation_session') instanceof AdminImpersonationSession) {
            return new JsonResponse(['error' => $this->translator->translate('http.error.forbidden')], 403);
        }

        $recoveryState      = $request->getAttribute('account_recovery');
        $recoveryMode       = is_array($recoveryState);
        $recoveryService    = $this->recoveryService;
        $pendingRecoveryId  = $pending['recovery_id']     ?? null;
        $pendingSessionHash = $pending['session_id_hash'] ?? null;
        $activeRecoveryId   = is_array($recoveryState) && is_string($recoveryState['recovery_id'] ?? null) ? $recoveryState['recovery_id'] : null;
        $activeSessionHash  = is_string($pendingSessionHash) ? $pendingSessionHash : null;
        if ($recoveryMode) {
            if (!$this->recoveryService instanceof AccountRecoveryService
                || !$session instanceof SessionIdentifierAwareInterface
                || $activeRecoveryId === null
                || $pendingRecoveryId !== $activeRecoveryId
                || $activeSessionHash === null
                || !hash_equals($activeSessionHash, hash('sha256', $session->getId()))
                || !$this->recoveryService->validateSession($currentUser->id, $activeRecoveryId, $activeSessionHash)) {
                $this->auditRecoveryFailure($request, $currentUser, 'session_invalid');
                return new JsonResponse(['error' => $this->translator->translate('recovery.session-expired')], 410);
            }
        } elseif ($pendingRecoveryId !== null) {
            return new JsonResponse(['error' => $this->translator->translate('http.error.forbidden')], 403);
        }

        try {
            $creationOptions = $this->webAuthn->deserializeCreationOptions($pending['options']);
            unset($parsed['csrf_token']);
            $credentialBody = json_encode($parsed, JSON_THROW_ON_ERROR);
            $source         = $this->webAuthn->parseAndValidateRegistration($credentialBody, $creationOptions);
        } catch (AuthenticatorResponseVerificationException|\InvalidArgumentException) {
            $this->auditRecoveryFailure($request, $currentUser, 'webauthn_validation_failed');
            return new JsonResponse(['error' => $this->translator->translate('webauthn.error.registration-failed')], 422);
        }

        $proof = null;
        if (!$recoveryMode) {
            $proof = $this->stepUp->consume($request, $currentUser->id, StepUpAction::PROFILE_WEBAUTHN_ENROLL, $currentUser->id);
            if (!$proof instanceof \TowerDNS\Application\DTO\StepUpProof) {
                return $this->stepUp->challengeAction($request, $currentUser->id, StepUpAction::PROFILE_WEBAUTHN_ENROLL, $currentUser->id, json: true);
            }
        }

        $maxCredentials = max(1, min(100, (int) $this->settings->get('security.webauthn.max_credentials_per_user', 10)));
        $attachment     = is_string($parsed['authenticatorAttachment'] ?? null) ? $parsed['authenticatorAttachment'] : null;
        try {
            if ($recoveryMode) {
                if (!$recoveryService instanceof AccountRecoveryService || $activeRecoveryId === null || $activeSessionHash === null) {
                    throw new \DomainException('The account recovery service is unavailable.');
                }
                $recoveryService->persistRecoveryWebAuthn($currentUser->id, $activeRecoveryId, $activeSessionHash, $pending['label'], $source, $attachment, $maxCredentials);
            } else {
                $this->credentialRepo->save($currentUser->id, $pending['label'], $source, $attachment, $maxCredentials);
            }
        } catch (WebAuthnCredentialLimitException) {
            $this->auditRecoveryFailure($request, $currentUser, 'credential_limit');
            return new JsonResponse(['error' => $this->translator->translate('webauthn.error.limit-reached')], 409);
        } catch (\DomainException) {
            $this->auditRecoveryFailure($request, $currentUser, 'session_invalid');
            return new JsonResponse(['error' => $this->translator->translate('recovery.session-expired')], 410);
        } catch (\Throwable $error) {
            if (!$recoveryMode) {
                throw $error;
            }
            $this->auditRecoveryFailure($request, $currentUser, 'credential_persistence_failed');
            return new JsonResponse(['error' => $this->translator->translate('recovery.enrollment-failed')], 500);
        }

        $this->audit->record($request, $recoveryMode ? 'security.account_recovery.webauthn.enrolled' : 'user.webauthn.credential.added', 'user', $currentUser->id, $currentUser->id, null, null, null, null, $currentUser->id, null, null, [
            'purpose'        => $pending['purpose'],
            'step_up_method' => $proof?->method,
        ]);

        return new JsonResponse(['ok' => true]);
    }

    private function auditRecoveryFailure(ServerRequestInterface $request, User $user, string $reason): void
    {
        if (is_array($request->getAttribute('account_recovery'))) {
            $this->audit->record($request, 'security.account_recovery.webauthn.enrollment.failed', 'user', $user->id, $user->id, metadata: ['reason' => $reason]);
        }
    }
}

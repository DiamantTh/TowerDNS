<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Translator\TranslatorInterface;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Session\SessionIdentifierAwareInterface;
use Mezzio\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Application\DTO\StepUpAction;
use TowerDNS\Application\Repository\SystemSettingsRepositoryInterface;
use TowerDNS\Application\Repository\WebAuthnCredentialRepositoryInterface;
use TowerDNS\Application\Services\AccountRecoveryService;
use TowerDNS\Application\Services\AuditLogService;
use TowerDNS\Application\Services\WebAuthnService;
use TowerDNS\Domain\Account\AdminImpersonationSession;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Infrastructure\Http\StepUpRequestService;

/**
 * POST /profile/webauthn/register/begin
 *
 * Generates WebAuthn registration options and stores the challenge in the session.
 * Returns JSON suitable for passing to navigator.credentials.create().
 *
 * Request body (form-encoded or JSON):
 *   name       – human-readable label for the new key
 *   csrf_token – CSRF token
 * @psalm-api Constructed through runtime dependency injection or command/handler registration.
 */
final readonly class WebAuthnRegisterBeginHandler implements RequestHandlerInterface
{
    public function __construct(
        private WebAuthnService                       $webAuthn,
        private WebAuthnCredentialRepositoryInterface $credentialRepo,
        private TranslatorInterface                   $translator,
        private StepUpRequestService                  $stepUp,
        private SystemSettingsRepositoryInterface     $settings,
        private AuditLogService                       $audit,
        private ?AccountRecoveryService               $recoveryService = null,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var \Mezzio\Csrf\CsrfGuardInterface $guard */
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);

        $body  = \TowerDNS\Infrastructure\Http\FormInput::fromParsedBody($request->getParsedBody());
        $token = ($body['csrf_token'] ?? '');

        if (!$guard->validateToken($token)) {
            return new JsonResponse(['error' => $this->translator->translate('http.error.invalid-request')], 400);
        }

        $name = trim(($body['name'] ?? ''));
        if ($name === '') {
            return new JsonResponse(['error' => $this->translator->translate('webauthn.error.name-required')], 422);
        }
        if (mb_strlen($name) > 64) {
            return new JsonResponse(['error' => $this->translator->translate('webauthn.error.name-too-long')], 422);
        }

        /** @var User $currentUser */
        $currentUser = $request->getAttribute(User::class);

        $session = $request->getAttribute(SessionInterface::class);
        assert($session instanceof SessionInterface);
        $recoveryState = $request->getAttribute('account_recovery');
        $recoveryMode  = is_array($recoveryState);
        $sessionIdHash = null;
        if ($recoveryMode) {
            if (!$this->recoveryService instanceof AccountRecoveryService
                || !$session instanceof SessionIdentifierAwareInterface
                || !is_string($recoveryState['recovery_id'] ?? null)) {
                return new JsonResponse(['error' => $this->translator->translate('recovery.session-expired')], 410);
            }
            $sessionIdHash = hash('sha256', $session->getId());
            if (!$this->recoveryService->validateSession($currentUser->id, $recoveryState['recovery_id'], $sessionIdHash)) {
                return new JsonResponse(['error' => $this->translator->translate('recovery.session-expired')], 410);
            }
        }
        if ($request->getAttribute('impersonation_session') instanceof AdminImpersonationSession) {
            return new JsonResponse(['error' => $this->translator->translate('http.error.forbidden')], 403);
        }

        $maxCredentials    = max(1, min(100, (int) $this->settings->get('security.webauthn.max_credentials_per_user', 10)));
        $credentialCount   = $this->credentialRepo->countByUserId($currentUser->id);
        $enrollmentCeiling = $recoveryMode
            ? $this->recoveryService?->webAuthnEnrollmentCeiling($recoveryState['recovery_id'], $maxCredentials)
            : $maxCredentials;
        $enrollmentCeiling ??= $maxCredentials;
        if ($credentialCount >= $enrollmentCeiling) {
            return new JsonResponse(['error' => $this->translator->translate('webauthn.error.limit-reached')], 409);
        }
        if (!$recoveryMode && !$this->stepUp->hasProof($request, $currentUser->id, StepUpAction::PROFILE_WEBAUTHN_ENROLL, $currentUser->id)) {
            return $this->stepUp->challengeAction($request, $currentUser->id, StepUpAction::PROFILE_WEBAUTHN_ENROLL, $currentUser->id, json: true);
        }

        // Collect existing credential IDs to pass as excludeCredentials.
        $existing   = $this->credentialRepo->findByUserId($currentUser->id);
        $excludeIds = array_column($existing, 'credential_id');

        $purpose = ($body['method'] ?? '') === 'security_key' ? 'hardware_security_key' : 'passkey';
        $options = $this->webAuthn->createRegistrationOptions(
            userId: $currentUser->id,
            userEmail: $currentUser->email,
            displayName: $currentUser->displayName ?? $currentUser->email,
            excludedCredentialIds: $excludeIds,
            hardwareSecurityKey: $purpose === 'hardware_security_key',
        );

        $createdAt = time();
        $session->set('webauthn_register_pending', [
            'user_id'         => $currentUser->id,
            'options'         => $this->webAuthn->serializeCreationOptions($options),
            'label'           => $name,
            'created_at'      => $createdAt,
            'expires_at'      => $createdAt + 300,
            'purpose'         => $purpose,
            'recovery_id'     => $recoveryMode ? $recoveryState['recovery_id'] : null,
            'session_id_hash' => $sessionIdHash,
        ]);
        $this->audit->record($request, $recoveryMode ? 'security.account_recovery.webauthn.enrollment.started' : 'security.webauthn.enrollment.started', 'user', $currentUser->id, $currentUser->id, null, null, null, null, $currentUser->id, null, null, ['purpose' => $purpose]);

        return new JsonResponse(
            json_decode($this->webAuthn->serializeCreationOptions($options), true),
            200,
        );
    }
}

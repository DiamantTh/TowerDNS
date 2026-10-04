<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http;

use Mezzio\Session\SessionInterface;
use Psr\Clock\ClockInterface;
use TowerDNS\Application\DTO\StepUpIntent;
use TowerDNS\Application\DTO\StepUpProof;
use TowerDNS\Application\Services\StepUpProofService;

/**
 * Keeps HTTP session lifecycle state in one place.
 *
 * This deliberately contains no authorization or user lookup logic. It only
 * manages short-lived MFA state and authenticated-session lifetime, so web
 * handlers cannot accidentally create subtly different session transitions.
 */
final readonly class SessionSecurity
{
    private const int MFA_TTL_SECONDS               = 300;
    private const int STEP_UP_TTL_SECONDS           = 300;
    private const int PASSWORD_REAUTH_TTL_SECONDS   = 600;
    private const int AUTH_IDLE_TIMEOUT_SECONDS     = 3600;
    private const int AUTH_ABSOLUTE_TIMEOUT_SECONDS = 28800;

    public function __construct(private ClockInterface $clock) {}

    public function beginMfa(SessionInterface $session, string $userId, string $type): SessionInterface
    {
        $session = $session->regenerate();
        $this->clearAuthenticatedState($session);
        $this->clearStepUp($session);
        $this->clearPendingMfa($session);
        $session->set('mfa_pending', $userId);
        $session->set('mfa_type', $type);
        $session->set('mfa_pending_started_at', $this->now());
        return $session;
    }

    public function pendingMfaUserId(SessionInterface $session): ?string
    {
        $userId    = $session->get('mfa_pending');
        $startedAt = $session->get('mfa_pending_started_at');

        if (!is_string($userId) || $userId === '' || !is_int($startedAt) || $startedAt < $this->now() - self::MFA_TTL_SECONDS) {
            $this->clearPendingMfa($session);
            return null;
        }

        return $userId;
    }

    public function completeLogin(SessionInterface $session, string $userId): SessionInterface
    {
        $session = $session->regenerate();
        $this->clearAuthenticatedState($session);
        $this->clearStepUp($session);
        $this->clearPendingMfa($session);
        $now = $this->now();
        $session->set('user_id', $userId);
        $session->set('authenticated_at', $now);
        $session->set('last_activity_at', $now);
        return $session;
    }

    /** Record the successful password part of authentication for safe factor enrollment. */
    public function markPasswordVerified(SessionInterface $session, string $userId): void
    {
        $session->set('password_verified_user_id', $userId);
        $session->set('password_verified_at', $this->now());
    }

    public function passwordVerifiedRecently(SessionInterface $session, string $userId): bool
    {
        $verifiedUser = $session->get('password_verified_user_id');
        $verifiedAt   = $session->get('password_verified_at');
        $now          = $this->now();

        if (!is_string($verifiedUser) || $verifiedUser !== $userId
                                      || !is_int($verifiedAt) || $verifiedAt > $now
                                      || $verifiedAt < $now - self::PASSWORD_REAUTH_TTL_SECONDS) {
            $session->unset('password_verified_user_id');
            $session->unset('password_verified_at');
            return false;
        }

        return true;
    }

    public function clearPasswordVerification(SessionInterface $session): void
    {
        $session->unset('password_verified_user_id');
        $session->unset('password_verified_at');
    }

    public function beginStepUp(
        SessionInterface $session,
        string $actorUserId,
        string $action,
        string $targetId,
        ?string $impersonationSessionId,
    ): SessionInterface {
        $session = $session->regenerate();
        $this->clearStepUp($session);
        $session->set('step_up_pending', [
            'actor_user_id'            => $actorUserId,
            'action'                   => $action,
            'target_id'                => $targetId,
            'impersonation_session_id' => $impersonationSessionId,
            'started_at'               => $this->now(),
        ]);

        return $session;
    }

    public function pendingStepUp(
        SessionInterface $session,
        string $actorUserId,
        ?string $impersonationSessionId,
    ): ?StepUpIntent {
        $pending = $session->get('step_up_pending');
        // A completed challenge intentionally removes step_up_pending while
        // retaining its signed proof for exactly one protected mutation.
        // Do not clear that proof merely because there is no pending intent.
        if ($pending === null) {
            return null;
        }

        if (!is_array($pending)
            || !is_string($pending['actor_user_id'] ?? null)
            || !is_string($pending['action'] ?? null)
            || !is_string($pending['target_id'] ?? null)
            || !is_string($pending['impersonation_session_id'] ?? null) && ($pending['impersonation_session_id'] ?? null) !== null
            || !is_int($pending['started_at'] ?? null)) {
            $this->clearStepUp($session);
            return null;
        }

        $now = $this->now();
        if ($pending['actor_user_id']               !== $actorUserId
            || $pending['impersonation_session_id'] !== $impersonationSessionId
            || $pending['started_at'] > $now
            || $pending['started_at'] < $now - self::STEP_UP_TTL_SECONDS) {
            $this->clearStepUp($session);
            return null;
        }

        return new StepUpIntent(
            $pending['actor_user_id'],
            $pending['action'],
            $pending['target_id'],
            $pending['impersonation_session_id'],
            $pending['started_at'],
        );
    }

    public function completeStepUp(
        SessionInterface $session,
        string $actorUserId,
        ?string $impersonationSessionId,
        string $method,
        StepUpProofService $proofs,
        ?string $credentialIdHash = null,
    ): ?StepUpProof {
        $intent = $this->pendingStepUp($session, $actorUserId, $impersonationSessionId);
        if (!$intent instanceof StepUpIntent) {
            return null;
        }

        $proof = $proofs->issue(
            $intent->actorUserId,
            $intent->action,
            $intent->targetId,
            $intent->impersonationSessionId,
            $method,
            $credentialIdHash,
        );
        $session->set('step_up_proof', $proof->toArray());
        $session->unset('step_up_pending');
        $session->unset('step_up_webauthn_options');

        return $proof;
    }

    public function consumeStepUpProof(
        SessionInterface $session,
        string $actorUserId,
        string $action,
        string $targetId,
        ?string $impersonationSessionId,
        StepUpProofService $proofs,
    ): ?StepUpProof {
        $value = $session->get('step_up_proof');
        $session->unset('step_up_proof');
        $proof = is_array($value) ? StepUpProof::fromArray($value) : null;

        return $proofs->isValid($proof, $actorUserId, $action, $targetId, $impersonationSessionId) ? $proof : null;
    }

    public function availableStepUpProof(SessionInterface $session, StepUpProofService $proofs): ?StepUpProof
    {
        $value = $session->get('step_up_proof');
        $proof = is_array($value) ? StepUpProof::fromArray($value) : null;
        if (!$proof instanceof StepUpProof || !$proofs->isValid($proof, $proof->actorUserId, $proof->action, $proof->targetId, $proof->impersonationSessionId)) {
            $session->unset('step_up_proof');
            return null;
        }

        return $proof;
    }

    public function clearStepUp(SessionInterface $session): void
    {
        $session->unset('step_up_pending');
        $session->unset('step_up_webauthn_options');
        $session->unset('step_up_proof');
    }

    public function authenticatedUserId(SessionInterface $session): ?string
    {
        $userId = $session->get('user_id');

        // Anonymous sessions legitimately contain CSRF tokens, password-reset
        // state, or an invitation flow. Do not clear that state merely because
        // no authenticated identity has been established yet.
        if (!is_string($userId) || $userId === '') {
            return null;
        }

        $authenticated = $session->get('authenticated_at');
        $lastActivity  = $session->get('last_activity_at');
        $now           = $this->now();

        if (!is_int($authenticated) || !is_int($lastActivity)
                                    || $authenticated < $now - self::AUTH_ABSOLUTE_TIMEOUT_SECONDS
                                    || $lastActivity  < $now - self::AUTH_IDLE_TIMEOUT_SECONDS) {
            $this->invalidate($session);
            return null;
        }

        $session->set('last_activity_at', $now);
        return $userId;
    }

    public function clearPendingMfa(SessionInterface $session): void
    {
        $session->unset('mfa_pending');
        $session->unset('mfa_type');
        $session->unset('mfa_pending_started_at');
        $session->unset('webauthn_auth_options');
    }

    public function invalidate(SessionInterface $session): void
    {
        $session->clear();
    }

    private function clearAuthenticatedState(SessionInterface $session): void
    {
        $session->unset('user_id');
        $session->unset('authenticated_at');
        $session->unset('last_activity_at');
        $session->unset('admin_switch_session_id');
        $session->unset('active_account_id');
        $this->clearStepUp($session);
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }
}

<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http;

use Mezzio\Session\SessionInterface;
use Psr\Clock\ClockInterface;

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
    private const int AUTH_IDLE_TIMEOUT_SECONDS     = 3600;
    private const int AUTH_ABSOLUTE_TIMEOUT_SECONDS = 28800;

    public function __construct(private ClockInterface $clock) {}

    public function beginMfa(SessionInterface $session, string $userId, string $type): SessionInterface
    {
        $session = $session->regenerate();
        $this->clearAuthenticatedState($session);
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
        $this->clearPendingMfa($session);
        $now = $this->now();
        $session->set('user_id', $userId);
        $session->set('authenticated_at', $now);
        $session->set('last_activity_at', $now);
        return $session;
    }

    public function authenticatedUserId(SessionInterface $session): ?string
    {
        $userId        = $session->get('user_id');
        $authenticated = $session->get('authenticated_at');
        $lastActivity  = $session->get('last_activity_at');
        $now           = $this->now();

        if (!is_string($userId) || $userId === '' || !is_int($authenticated) || !is_int($lastActivity)
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
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }
}

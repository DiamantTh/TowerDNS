<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Http;

use Mezzio\Session\Session;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use TowerDNS\Infrastructure\Http\SessionSecurity;

final class SessionSecurityTest extends TestCase
{
    public function testCompletingLoginRegeneratesTheSessionAndRecordsItsLifetime(): void
    {
        $session  = new Session([]);
        $security = new SessionSecurity($this->clock(1000));

        $session = $security->completeLogin($session, 'user-1');

        self::assertTrue($session->isRegenerated());
        self::assertSame('user-1', $security->authenticatedUserId($session));
        self::assertSame(1000, $session->get('authenticated_at'));
        self::assertSame(1000, $session->get('last_activity_at'));
    }

    public function testIdleOrAbsoluteSessionExpiryInvalidatesAllState(): void
    {
        $session = new Session([
            'user_id'                 => 'user-1',
            'authenticated_at'        => 1,
            'last_activity_at'        => 1,
            'admin_switch_session_id' => 'switch',
        ]);

        self::assertNull(new SessionSecurity($this->clock(3602))->authenticatedUserId($session));
        self::assertSame([], $session->toArray());
    }

    public function testPendingMfaExpiresAndCannotBecomeAnAuthenticatedSession(): void
    {
        $session  = new Session([]);
        $security = new SessionSecurity($this->clock(1000));
        $session  = $security->beginMfa($session, 'user-1', 'totp');

        self::assertTrue($session->isRegenerated());
        self::assertSame('user-1', $security->pendingMfaUserId($session));
        self::assertNull(new SessionSecurity($this->clock(1301))->pendingMfaUserId($session));
        self::assertFalse($session->has('mfa_pending'));
        self::assertFalse($session->has('webauthn_auth_options'));
    }

    public function testLoginCompletionClearsPendingMfaAndLogoutClearsEverything(): void
    {
        $session = new Session([
            'mfa_pending'            => 'user-1',
            'mfa_type'               => 'webauthn',
            'mfa_pending_started_at' => 1000,
            'webauthn_auth_options'  => 'challenge',
        ]);
        $security = new SessionSecurity($this->clock(1001));

        $session = $security->completeLogin($session, 'user-1');
        self::assertFalse($session->has('mfa_pending'));
        self::assertFalse($session->has('webauthn_auth_options'));
        self::assertSame('user-1', $security->authenticatedUserId($session));

        $security->invalidate($session);
        self::assertSame([], $session->toArray());
    }

    private function clock(int $timestamp): ClockInterface
    {
        return new readonly class ($timestamp) implements ClockInterface {
            public function __construct(private int $timestamp) {}

            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable()->setTimestamp($this->timestamp);
            }
        };
    }
}

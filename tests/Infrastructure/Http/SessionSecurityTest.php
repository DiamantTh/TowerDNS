<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Http;

use Mezzio\Session\Session;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use TowerDNS\Application\DTO\StepUpAction;
use TowerDNS\Application\Repository\StepUpProofNonceRepositoryInterface;
use TowerDNS\Application\Services\StepUpProofService;
use TowerDNS\Infrastructure\Http\SessionSecurity;

/** @psalm-api Runtime discovery by PHPUnit or local module loading is not statically visible. */
final class SessionSecurityTest extends TestCase
{
    public function testCompletingLoginRegeneratesTheSessionAndRecordsItsLifetime(): void
    {
        $session  = new Session(['active_account_id' => 42, 'admin_switch_session_id' => 'old-switch']);
        $security = new SessionSecurity($this->clock(1000));

        $session = $security->completeLogin($session, 'user-1');

        self::assertTrue($session->isRegenerated());
        self::assertSame('user-1', $security->authenticatedUserId($session));
        self::assertSame(1000, $session->get('authenticated_at'));
        self::assertSame(1000, $session->get('last_activity_at'));
        self::assertFalse($session->has('active_account_id'));
        self::assertFalse($session->has('admin_switch_session_id'));
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

    public function testAnonymousSessionStateIsPreserved(): void
    {
        $session = new Session(['__csrf' => 'csrf-token']);

        self::assertNull(new SessionSecurity($this->clock(1000))->authenticatedUserId($session));
        self::assertSame('csrf-token', $session->get('__csrf'));
    }

    public function testStartingRecoveryClearsIdentityStepUpAndFreshPasswordState(): void
    {
        $session = new Session([
            'user_id'                   => 'admin-1',
            'auth_session_version'      => 8,
            'authenticated_at'          => 1000,
            'last_activity_at'          => 1000,
            'admin_switch_session_id'   => 'switch-1',
            'password_verified_user_id' => 'admin-1',
            'password_verified_at'      => 1000,
            'step_up_proof'             => ['opaque' => 'proof'],
            'webauthn_register_pending' => ['opaque' => 'pending'],
        ]);

        $session = new SessionSecurity($this->clock(1001))->beginRecovery($session);

        self::assertTrue($session->isRegenerated());
        self::assertFalse($session->has('user_id'));
        self::assertFalse($session->has('admin_switch_session_id'));
        self::assertFalse($session->has('password_verified_user_id'));
        self::assertFalse($session->has('step_up_proof'));
        self::assertFalse($session->has('webauthn_register_pending'));
    }

    public function testEndingRecoveryConsumesItsRestrictedSessionState(): void
    {
        $session = new Session([
            'account_recovery'          => ['user_id' => 'user-1', 'recovery_id' => 'recovery-1'],
            'webauthn_register_pending' => ['opaque' => 'pending'],
            'step_up_proof'             => ['opaque' => 'proof'],
            'mfa_pending'               => 'user-1',
        ]);

        new SessionSecurity($this->clock(1000))->endRecovery($session);

        self::assertFalse($session->has('account_recovery'));
        self::assertFalse($session->has('webauthn_register_pending'));
        self::assertFalse($session->has('step_up_proof'));
        self::assertFalse($session->has('mfa_pending'));
    }

    public function testPendingMfaExpiresAndCannotBecomeAnAuthenticatedSession(): void
    {
        $session  = new Session([]);
        $security = new SessionSecurity($this->clock(1000));
        $session  = $security->beginMfa($session, 'user-1', 'totp', 7);

        self::assertTrue($session->isRegenerated());
        self::assertSame('user-1', $security->pendingMfaUserId($session));
        self::assertSame(7, $security->pendingMfaSessionVersion($session));
        self::assertNull(new SessionSecurity($this->clock(1301))->pendingMfaUserId($session));
        self::assertFalse($session->has('mfa_pending'));
        self::assertFalse($session->has('mfa_auth_session_version'));
        self::assertFalse($session->has('webauthn_auth_options'));
    }

    public function testCompletingLoginConsumesTheMfaSessionVersionSnapshot(): void
    {
        $session  = new Session([]);
        $security = new SessionSecurity($this->clock(1000));
        $session  = $security->beginMfa($session, 'user-1', 'webauthn', 4);

        self::assertSame(4, $security->pendingMfaSessionVersion($session));

        $session = $security->completeLogin($session, 'user-1', 4);

        self::assertNull($security->pendingMfaSessionVersion($session));
        self::assertSame(4, $session->get('auth_session_version'));
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

    public function testRecentPasswordVerificationIsBoundToUserAndExpires(): void
    {
        $session  = new Session([]);
        $security = new SessionSecurity($this->clock(1000));
        $security->markPasswordVerified($session, 'user-1');

        self::assertTrue($security->passwordVerifiedRecently($session, 'user-1'));
        self::assertFalse($security->passwordVerifiedRecently($session, 'user-2'));
        self::assertFalse(new SessionSecurity($this->clock(1601))->passwordVerifiedRecently($session, 'user-1'));
    }

    public function testStepUpProofIsBoundToIntentAndCanBeConsumedOnlyOnce(): void
    {
        $session  = new Session([]);
        $clock    = $this->clock(1000);
        $security = new SessionSecurity($clock);
        $proofs   = new StepUpProofService(str_repeat('k', 32), $clock, $this->nonceRepository());
        $session  = $security->beginStepUp($session, 'user-1', StepUpAction::IAM_USER_ROLES, 'target-1', 'switch-1');

        self::assertNotNull($security->pendingStepUp($session, 'user-1', 'switch-1'));
        self::assertNull($security->pendingStepUp($session, 'user-1', null));
        $session = $security->beginStepUp(new Session([]), 'user-1', StepUpAction::IAM_USER_ROLES, 'target-1', 'switch-1');

        $proof = $security->completeStepUp($session, 'user-1', 'switch-1', 'totp', $proofs, hash('sha256', 'totp'));
        self::assertNotNull($proof);
        self::assertSame('totp', $proof->method);
        self::assertNull($security->pendingStepUp($session, 'user-1', 'switch-1'));

        self::assertNull($security->consumeStepUpProof($session, 'user-1', StepUpAction::IAM_USER_ROLES, 'target-2', 'switch-1', $proofs));
        self::assertNull($security->consumeStepUpProof($session, 'user-1', StepUpAction::IAM_USER_ROLES, 'target-1', 'switch-1', $proofs));
    }

    public function testStepUpProofExpiresAndIsInvalidatedByLogout(): void
    {
        $session  = new Session([]);
        $security = new SessionSecurity($this->clock(1000));
        $proofs   = new StepUpProofService(str_repeat('k', 32), $this->clock(1000), $this->nonceRepository());
        $session  = $security->beginStepUp($session, 'user-1', StepUpAction::IAM_USER_STATUS, 'target-1', null);
        self::assertNotNull($security->completeStepUp($session, 'user-1', null, 'webauthn', $proofs, hash('sha256', 'key')));

        $expired = new SessionSecurity($this->clock(1301))->consumeStepUpProof($session, 'user-1', StepUpAction::IAM_USER_STATUS, 'target-1', null, new StepUpProofService(str_repeat('k', 32), $this->clock(1301), $this->nonceRepository()));
        self::assertNull($expired);

        $session  = new Session([]);
        $security = new SessionSecurity($this->clock(1000));
        $session  = $security->beginStepUp($session, 'user-1', StepUpAction::IAM_USER_STATUS, 'target-1', null);
        $security->completeStepUp($session, 'user-1', null, 'totp', $proofs, hash('sha256', 'totp'));
        $security->invalidate($session);
        self::assertSame([], $session->toArray());
    }

    public function testCompletedStepUpProofSurvivesLookupForMissingPendingIntent(): void
    {
        $session   = new Session([]);
        $clock     = $this->clock(1000);
        $security  = new SessionSecurity($clock);
        $proofs    = new StepUpProofService(str_repeat('k', 32), $clock, $this->nonceRepository());
        $session   = $security->beginStepUp($session, 'user-1', StepUpAction::IAM_USER_ROLES, 'target-1', null);
        $completed = $security->completeStepUp($session, 'user-1', null, 'totp', $proofs, hash('sha256', 'totp'));

        self::assertNotNull($completed);
        self::assertNull($security->pendingStepUp($session, 'user-1', null));
        self::assertNotNull($security->availableStepUpProof($session, $proofs));
        self::assertNotNull($security->consumeStepUpProof($session, 'user-1', StepUpAction::IAM_USER_ROLES, 'target-1', null, $proofs));
        self::assertNull($security->availableStepUpProof($session, $proofs));
    }

    private function clock(int $timestamp): ClockInterface
    {
        return new readonly class ($timestamp) implements ClockInterface {
            public function __construct(private int $timestamp) {}

            #[\Override]
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable()->setTimestamp($this->timestamp);
            }
        };
    }

    private function nonceRepository(): StepUpProofNonceRepositoryInterface
    {
        $nonces = $this->createMock(StepUpProofNonceRepositoryInterface::class);
        $nonces->method('claim')->willReturn(true);

        return $nonces;
    }
}

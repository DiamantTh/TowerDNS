<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Application\Services;

use PHPUnit\Framework\TestCase;
use TowerDNS\Application\Services\AuthenticationPathPolicy;

/** @psalm-api Runtime discovery by PHPUnit or local module loading is not statically visible. */
final class AuthenticationPathPolicyTest extends TestCase
{
    public function testFidoRemovalRequiresAtLeastTwoExistingCredentials(): void
    {
        self::assertTrue(AuthenticationPathPolicy::permitsWebAuthnRemoval(3));
        self::assertTrue(AuthenticationPathPolicy::permitsWebAuthnRemoval(2));
        self::assertFalse(AuthenticationPathPolicy::permitsWebAuthnRemoval(1));
        self::assertFalse(AuthenticationPathPolicy::permitsWebAuthnRemoval(0));
    }

    public function testThreeOrMoreTotpCredentialsPermitAnyEnrolledTotpOrFidoStepUp(): void
    {
        $credential = hash('sha256', 'authenticator');
        $target     = hash('sha256', 'target');

        self::assertTrue(AuthenticationPathPolicy::permitsTotpRemoval(3, 0, 'totp', $credential, $target));
        self::assertTrue(AuthenticationPathPolicy::permitsTotpRemoval(4, 1, 'webauthn', $credential, $target));
        self::assertFalse(AuthenticationPathPolicy::permitsTotpRemoval(3, 0, 'password', $credential, $target));
    }

    public function testTwoTotpCredentialsRequireTheOtherTotpOrFido(): void
    {
        $other  = hash('sha256', 'other');
        $target = hash('sha256', 'target');

        self::assertTrue(AuthenticationPathPolicy::permitsTotpRemoval(2, 0, 'totp', $other, $target));
        self::assertFalse(AuthenticationPathPolicy::permitsTotpRemoval(2, 0, 'totp', $target, $target));
        self::assertTrue(AuthenticationPathPolicy::permitsTotpRemoval(2, 1, 'webauthn', $target, $target));
        self::assertFalse(AuthenticationPathPolicy::permitsTotpRemoval(2, 0, 'webauthn', $target, $target));
    }

    public function testLastTotpCanOnlyBeRemovedWithFidoWhenFidoCredentialExists(): void
    {
        $fido   = hash('sha256', 'fido');
        $target = hash('sha256', 'target');

        self::assertTrue(AuthenticationPathPolicy::permitsTotpRemoval(1, 1, 'webauthn', $fido, $target));
        self::assertFalse(AuthenticationPathPolicy::permitsTotpRemoval(1, 1, 'totp', $target, $target));
        self::assertFalse(AuthenticationPathPolicy::permitsTotpRemoval(1, 0, 'webauthn', $fido, $target));
    }

    public function testPasswordCanOnlyBeDisabledWhilePasswordlessWebAuthnRemains(): void
    {
        self::assertFalse(AuthenticationPathPolicy::permitsPasswordDisable(0));
        self::assertTrue(AuthenticationPathPolicy::permitsPasswordDisable(1));
    }
}

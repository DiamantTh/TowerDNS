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
    public function testPasskeyOnlyAccountCannotLoseItsLastKeyButKeepsMultipleKeysManageable(): void
    {
        self::assertFalse(AuthenticationPathPolicy::permitsWebAuthnRemoval(0, false, false));
        self::assertTrue(AuthenticationPathPolicy::permitsWebAuthnRemoval(1, false, false));
    }

    public function testLastWebAuthnKeyCanBeRemovedWhenPasswordAndTotpRemainAsMfa(): void
    {
        self::assertTrue(AuthenticationPathPolicy::permitsWebAuthnRemoval(0, true, true));
        self::assertFalse(AuthenticationPathPolicy::permitsWebAuthnRemoval(0, true, false));
        self::assertFalse(AuthenticationPathPolicy::permitsWebAuthnRemoval(0, false, true));
    }

    public function testFinalTotpCredentialRequiresAnotherPrimaryLoginFactor(): void
    {
        self::assertFalse(AuthenticationPathPolicy::permitsTotpRemoval(0, 0));
        self::assertTrue(AuthenticationPathPolicy::permitsTotpRemoval(1, 0));
        self::assertTrue(AuthenticationPathPolicy::permitsTotpRemoval(0, 1));
    }

    public function testPasswordCanOnlyBeDisabledWhilePasswordlessWebAuthnRemains(): void
    {
        self::assertFalse(AuthenticationPathPolicy::permitsPasswordDisable(0));
        self::assertTrue(AuthenticationPathPolicy::permitsPasswordDisable(1));
    }
}

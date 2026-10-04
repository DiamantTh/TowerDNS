<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Services;

/** Lockout rules for removing authentication factors and the optional password. */
final class AuthenticationPathPolicy
{
    public static function permitsWebAuthnRemoval(int $remainingWebAuthnCredentials, bool $passwordAvailable, bool $totpAvailable): bool
    {
        // TOTP is a second factor, not a primary login. Removing the last key
        // therefore needs a password plus TOTP to retain the existing MFA path.
        return $remainingWebAuthnCredentials > 0 || ($passwordAvailable && $totpAvailable);
    }

    public static function permitsTotpRemoval(int $remainingTotpCredentials, int $webAuthnCredentialCount): bool
    {
        // TOTP alone cannot start a login; keep it when there is no FIDO key.
        return $remainingTotpCredentials > 0 || $webAuthnCredentialCount > 0;
    }

    public static function permitsPasswordDisable(int $webAuthnCredentialCount): bool
    {
        // A FIDO credential can authenticate without a password.
        return $webAuthnCredentialCount > 0;
    }
}

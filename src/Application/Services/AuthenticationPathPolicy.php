<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Services;

/** Lockout rules for removing authentication factors and the optional password. */
final class AuthenticationPathPolicy
{
    public static function permitsWebAuthnRemoval(int $existingWebAuthnCredentials): bool
    {
        // Self-service always retains at least one key. Password/TOTP are not
        // substitutes for the required FIDO2 confirmation or the admin path.
        return $existingWebAuthnCredentials >= 2;
    }

    public static function permitsTotpRemoval(
        int $existingTotpCredentials,
        int $webAuthnCredentialCount,
        string $stepUpMethod,
        ?string $verifiedCredentialIdHash,
        string $targetCredentialIdHash,
    ): bool {
        if ($existingTotpCredentials < 1 || !in_array($stepUpMethod, ['totp', 'webauthn'], true)
                                         || !is_string($verifiedCredentialIdHash)
                                         || preg_match('/^[a-f0-9]{64}$/D', $verifiedCredentialIdHash) !== 1) {
            return false;
        }

        if ($existingTotpCredentials >= 3) {
            return true;
        }

        if ($existingTotpCredentials === 2) {
            return ($stepUpMethod === 'webauthn' && $webAuthnCredentialCount > 0)
                || ($stepUpMethod === 'totp' && !hash_equals($targetCredentialIdHash, $verifiedCredentialIdHash));
        }

        // The last TOTP factor may only be removed with a current FIDO2 proof.
        return $webAuthnCredentialCount > 0 && $stepUpMethod === 'webauthn';
    }

    public static function permitsPasswordDisable(int $webAuthnCredentialCount): bool
    {
        // A FIDO credential can authenticate without a password.
        return $webAuthnCredentialCount > 0;
    }
}

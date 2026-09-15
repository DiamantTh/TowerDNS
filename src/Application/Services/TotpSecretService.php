<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Services;

use TowerDNS\Application\Contracts\CredentialEncryptorInterface;
use TowerDNS\Application\Repository\UserRepositoryInterface;

/**
 * Owns encrypted TOTP persistence and short-lived verification plaintext.
 *
 * This service is transport-neutral so web, CLI, and a future API use the
 * same MFA-secret boundary. The repository only ever receives ciphertext.
 */
final readonly class TotpSecretService
{
    public function __construct(
        private UserRepositoryInterface $users,
        private TotpService $totp,
        private CredentialEncryptorInterface $cipher,
    ) {}

    public function isEnabled(string $userId): bool
    {
        return $this->users->fetchEncryptedTotpSecret($userId) !== null;
    }

    /** @param string $secret Base32 TOTP secret verified during setup. */
    public function enable(string $userId, string $secret): void
    {
        $ciphertext = '';
        try {
            $ciphertext = $this->cipher->encrypt($secret);
            $this->users->saveEncryptedTotpSecret($userId, $ciphertext);
        } finally {
            $this->cipher->wipe($secret);
        }
    }

    /**
     * Invalid, tampered, or undecryptable ciphertext is intentionally treated
     * as a failed MFA attempt. It never falls back to legacy plaintext data.
     */
    public function verify(string $userId, string $code): bool
    {
        $ciphertext = $this->users->fetchEncryptedTotpSecret($userId);
        if ($ciphertext === null || $code === '') {
            return false;
        }

        $secret = '';
        try {
            $secret = $this->cipher->decrypt($ciphertext);
            return $this->totp->verify($code, $secret);
        } catch (\Throwable) {
            return false;
        } finally {
            if ($secret !== '') {
                $this->cipher->wipe($secret);
            }
        }
    }

    public function disable(string $userId): void
    {
        $this->users->saveEncryptedTotpSecret($userId, null);
    }
}

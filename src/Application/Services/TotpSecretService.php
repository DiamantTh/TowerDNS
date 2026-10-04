<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Services;

use TowerDNS\Application\Contracts\CredentialEncryptorInterface;
use TowerDNS\Application\Exception\TotpCredentialLimitException;
use TowerDNS\Application\Repository\SystemSettingsRepositoryInterface;
use TowerDNS\Application\Repository\TotpCredentialRepositoryInterface;

/** Owns encrypted, independently managed TOTP credentials. */
final readonly class TotpSecretService
{
    public const int DEFAULT_MAX_CREDENTIALS    = 5;
    public const int MAX_CREDENTIALS_HARD_LIMIT = 100;

    public function __construct(
        private TotpCredentialRepositoryInterface $credentials,
        private TotpService $totp,
        private CredentialEncryptorInterface $cipher,
        private SystemSettingsRepositoryInterface $settings,
    ) {}

    public function isEnabled(string $userId): bool
    {
        return $this->credentials->countByUserId($userId) > 0;
    }

    public function count(string $userId): int
    {
        return $this->credentials->countByUserId($userId);
    }

    public function maxCredentialsPerUser(): int
    {
        $value = $this->settings->get('security.totp.max_credentials_per_user', self::DEFAULT_MAX_CREDENTIALS);
        return max(1, min(self::MAX_CREDENTIALS_HARD_LIMIT, is_numeric($value) ? (int) $value : self::DEFAULT_MAX_CREDENTIALS));
    }

    /** @return list<array{id: string, label: string, created_at: string, last_used_at: string|null}> */
    public function list(string $userId): array
    {
        return array_map(static fn(array $credential): array => [
            'id'           => $credential['id'],
            'label'        => $credential['label'],
            'created_at'   => $credential['created_at'],
            'last_used_at' => $credential['last_used_at'],
        ], $this->credentials->findByUserId($userId));
    }

    /** @param non-empty-string $secret Secret must already have been verified against a code. */
    public function enable(string $userId, string $secret, string $label = 'Authenticator'): string
    {
        if ($this->count($userId) >= $this->maxCredentialsPerUser()) {
            $this->cipher->wipe($secret);
            throw new TotpCredentialLimitException('The configured TOTP credential limit has been reached.');
        }

        try {
            $ciphertext = $this->cipher->encrypt($secret);
            $id         = $this->newId();
            $this->credentials->save($id, $userId, $ciphertext, $this->normalizeLabel($label), $this->maxCredentialsPerUser());
            return $id;
        } finally {
            $this->cipher->wipe($secret);
        }
    }

    /**
     * Invalid, tampered, or undecryptable data is a failed MFA attempt. The
     * stored ciphertext is never interpreted as plaintext.
     */
    public function verify(string $userId, string $code): bool
    {
        if ($code === '') {
            return false;
        }

        foreach ($this->credentials->findByUserId($userId) as $credential) {
            $secret = '';
            try {
                $secret = $this->cipher->decrypt($credential['secret_encrypted']);
                if ($this->totp->verify($code, $secret)) {
                    $this->credentials->markUsed($credential['id'], $userId);
                    return true;
                }
            } catch (\Throwable) {
                // A damaged credential must not prevent other enrolled factors from working.
            } finally {
                if ($secret !== '') {
                    $this->cipher->wipe($secret);
                }
            }
        }

        return false;
    }

    public function delete(string $userId, string $credentialId): void
    {
        $this->credentials->delete($credentialId, $userId);
    }

    private function normalizeLabel(string $label): string
    {
        $label = trim($label);
        if ($label === '') {
            return 'Authenticator';
        }
        if (mb_strlen($label) > 100) {
            throw new \InvalidArgumentException('TOTP credential label must not exceed 100 characters.');
        }

        return $label;
    }

    private function newId(): string
    {
        $bytes    = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex      = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}

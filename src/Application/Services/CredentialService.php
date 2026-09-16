<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Services;

use TowerDNS\Application\Contracts\CredentialEncryptorInterface;

/**
 * Symmetric authenticated encryption for DNS provider credentials.
 *
 * Uses the application-level encryption key from config.local.toml
 * ([security] encryption_key = "<base64-32-bytes>").
 *
 * Intentionally uses the app-key (not a per-user session key) because:
 *  - Admin-Switch must be able to use provider credentials on behalf of any user.
 *  - Password resets must not destroy provider connections.
 *  - Multiple team members share access to the same ProviderAccount.
 *  - Credentials are account-owned, not user-owned.
 *
 * Cipher hierarchy (auto-detected at runtime, best available wins):
 *   v3  AEGIS-256            (libsodium ≥ 1.0.19) — prefix 0x03
 *   v2  XChaCha20-Poly1305   (libsodium ≥ 1.0.12) — prefix 0x02
 *   v1  XSalsa20-Poly1305    (secretbox, fallback)  — prefix 0x01
 *
 * All versions use 32-byte keys — no key migration needed when upgrading cipher.
 *
 * SECURITY REQUIREMENTS:
 *   - Encrypted blobs are stored in `provider_accounts.credentials_encrypted`.
 *   - Decrypted values MUST be short-lived and MUST NOT be logged, returned to
 *     the frontend, written to exceptions, or serialised anywhere.
 *   - Use {@see self::wipe()} to zero plaintext strings after use.
 */
final readonly class CredentialService implements CredentialEncryptorInterface
{
    private const string V3_AEGIS256  = "\x03";
    private const string V2_XCHACHA20 = "\x02";
    private const string V1_SECRETBOX = "\x01";

    /** 32-byte raw key */
    private string $rawKey;

    /**
     * @param string $b64AppKey  Base64-encoded 32-byte key from [security] encryption_key
     * @throws \RuntimeException on invalid key
     */
    public function __construct(string $b64AppKey)
    {
        if ($b64AppKey === '') {
            throw new \RuntimeException('CredentialService: encryption_key not configured.');
        }

        $raw = base64_decode($b64AppKey, strict: true);

        if ($raw === false || strlen($raw) !== \SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException(
                'CredentialService: encryption_key must be exactly 32 bytes (base64-encoded). '
                . 'Use CredentialService::generateKey() to generate one.'
            );
        }

        $this->rawKey = $raw;
    }

    // ── Encryption ───────────────────────────────────────────────────────────

    /**
     * Encrypts a credential payload (JSON string).
     * Returns a base64-encoded ciphertext blob with version prefix.
     *
     * @param string $plaintext  Credential JSON to encrypt
     * @throws \RuntimeException on encryption failure
     */
    public function encrypt(string $plaintext): string
    {
        if (defined('SODIUM_CRYPTO_AEAD_AEGIS256_KEYBYTES')) {
            /** @var positive-int $npub */
            $npub  = \SODIUM_CRYPTO_AEAD_AEGIS256_NPUBBYTES;
            $nonce = random_bytes($npub);
            $blob  = sodium_crypto_aead_aegis256_encrypt($plaintext, '', $nonce, $this->rawKey);
            sodium_memzero($plaintext);
            return base64_encode(self::V3_AEGIS256 . $nonce . $blob);
        }

        if (defined('SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES')) {
            /** @var positive-int $npub */
            $npub  = \SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
            $nonce = random_bytes($npub);
            $blob  = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, '', $nonce, $this->rawKey);
            sodium_memzero($plaintext);
            return base64_encode(self::V2_XCHACHA20 . $nonce . $blob);
        }

        // fallback: XSalsa20-Poly1305 secretbox
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $blob  = sodium_crypto_secretbox($plaintext, $nonce, $this->rawKey);
        sodium_memzero($plaintext);
        return base64_encode(self::V1_SECRETBOX . $nonce . $blob);
    }

    // ── Decryption ───────────────────────────────────────────────────────────

    /**
     * Decrypts a ciphertext blob produced by {@see self::encrypt()}.
     *
     * IMPORTANT: The returned plaintext is short-lived. Call {@see self::wipe()}
     * on it as soon as the provider client has been constructed.
     *
     * @param string $encoded  Base64-encoded ciphertext blob
     * @return string  Plaintext JSON credential string
     * @throws \RuntimeException on authentication failure or invalid format
     */
    public function decrypt(string $encoded): string
    {
        $raw = base64_decode($encoded, strict: true);
        if ($raw === false || strlen($raw) < 2) {
            throw new \RuntimeException('CredentialService: invalid ciphertext blob.');
        }

        $version = $raw[0];
        $payload = substr($raw, 1);

        return match ($version) {
            self::V3_AEGIS256  => $this->decryptAegis256($payload),
            self::V2_XCHACHA20 => $this->decryptXChacha20($payload),
            self::V1_SECRETBOX => $this->decryptSecretbox($payload),
            default            => throw new \RuntimeException('CredentialService: unknown cipher version.'),
        };
    }

    /**
     * Zeroes a plaintext string in memory after use.
     * Always call this after constructing the provider client.
     */
    public function wipe(string &$plaintext): void
    {
        if (function_exists('sodium_memzero')) {
            /** @phpstan-ignore parameterByRef.type */
            sodium_memzero($plaintext);
            // sodium_memzero nullifies the variable; restore to empty string for type safety
            $plaintext = '';
        } else {
            $plaintext = str_repeat("\x00", strlen($plaintext));
        }
    }

    // ── Key generation ────────────────────────────────────────────────────────

    /**
     * Generates a new random 32-byte key, base64-encoded.
     * Use this during installation to populate [security] encryption_key.
     */
    public static function generateKey(): string
    {
        return base64_encode(random_bytes(\SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    // ── Credentials version ───────────────────────────────────────────────────

    /**
     * Returns the current cipher version integer (1, 2 or 3).
     * Stored in `provider_accounts.credentials_version` for rotation tracking.
     */
    public static function currentVersion(): int
    {
        if (defined('SODIUM_CRYPTO_AEAD_AEGIS256_KEYBYTES')) {
            return 3;
        }
        if (defined('SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES')) {
            return 2;
        }
        return 1;
    }

    // ── Private decryptors ────────────────────────────────────────────────────

    private function decryptAegis256(string $payload): string
    {
        if (!defined('SODIUM_CRYPTO_AEAD_AEGIS256_NPUBBYTES')) {
            throw new \RuntimeException('CredentialService: AEGIS-256 not available on this system.');
        }
        /** @var int $npub */
        $npub  = \SODIUM_CRYPTO_AEAD_AEGIS256_NPUBBYTES;
        $nonce = substr($payload, 0, $npub);
        $blob  = substr($payload, $npub);
        $plain = sodium_crypto_aead_aegis256_decrypt($blob, '', $nonce, $this->rawKey);
        if ($plain === false) {
            throw new \RuntimeException('CredentialService: AEGIS-256 decryption failed (wrong key or tampered data).');
        }
        return $plain;
    }

    private function decryptXChacha20(string $payload): string
    {
        if (!defined('SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES')) {
            throw new \RuntimeException('CredentialService: XChaCha20-Poly1305 not available on this system.');
        }
        /** @var int $npub */
        $npub  = \SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        $nonce = substr($payload, 0, $npub);
        $blob  = substr($payload, $npub);
        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($blob, '', $nonce, $this->rawKey);
        if ($plain === false) {
            throw new \RuntimeException('CredentialService: XChaCha20 decryption failed (wrong key or tampered data).');
        }
        return $plain;
    }

    private function decryptSecretbox(string $payload): string
    {
        $nonce = substr($payload, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $blob  = substr($payload, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($blob, $nonce, $this->rawKey);
        if ($plain === false) {
            throw new \RuntimeException('CredentialService: secretbox decryption failed (wrong key or tampered data).');
        }
        return $plain;
    }
}

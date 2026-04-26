<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Domain\Account;

/**
 * A stored DNS-provider credential set belonging to an Account.
 *
 * Credentials are NEVER stored in plain text and are NEVER returned to
 * any caller in decrypted form — only the {@see \TowerDNS\Application\Services\CredentialService}
 * may decrypt them transiently in memory for constructing a provider client.
 *
 * The `credentialsEncrypted` field always holds a libsodium ciphertext blob.
 * The `credentialsVersion` field tracks the cipher generation for key rotation.
 */
final readonly class ProviderAccount
{
    public function __construct(
        public int     $id,
        public int     $accountId,
        /** Provider type identifier matching DnsProviderInterface::id(). e.g. 'desec', 'cloudflare', 'inwx' */
        public string  $providerType,
        public string  $name,
        public string  $credentialsEncrypted,
        public int     $credentialsVersion,
        public bool    $isActive,
        public string  $createdAt,
        public ?string $lastTestedAt = null,
        public ?string $lastUsedAt = null,
    ) {}
}

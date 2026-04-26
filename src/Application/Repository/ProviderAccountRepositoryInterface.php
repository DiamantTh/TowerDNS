<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Repository;

use TowerDNS\Domain\Account\ProviderAccount;

interface ProviderAccountRepositoryInterface
{
    public function findById(int $id): ?ProviderAccount;

    /**
     * Returns all provider accounts for a given account (tenant).
     * Credentials are still encrypted — use CredentialService to decrypt.
     * @return list<ProviderAccount>
     */
    public function findByAccountId(int $accountId): array;

    /**
     * Returns active provider accounts of a specific type for an account.
     * Used by ProviderFactory when resolving the provider for a zone.
     * @return list<ProviderAccount>
     */
    public function findActiveByAccountAndType(int $accountId, string $providerType): array;

    /**
     * Creates a new ProviderAccount.
     * $credentialsEncrypted must already be encrypted via CredentialService.
     */
    public function create(
        int    $accountId,
        string $providerType,
        string $name,
        string $credentialsEncrypted,
        int    $credentialsVersion,
        string $createdAt,
    ): int;

    /**
     * Replaces the encrypted credentials (key rotation / re-keying).
     * The old credentials are overwritten — they are never readable again.
     */
    public function replaceCredentials(
        int    $id,
        int    $accountId,
        string $credentialsEncrypted,
        int    $credentialsVersion,
    ): void;

    public function touchLastUsed(int $id, string $timestamp): void;

    public function touchLastTested(int $id, string $timestamp): void;

    public function deactivate(int $id, int $accountId): void;

    public function delete(int $id, int $accountId): void;
}

<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Contracts;

/**
 * Provider credential metadata and validation exposed to application services.
 * Constructing a concrete provider adapter intentionally remains outside this
 * contract.
 */
interface ProviderCredentialSchemaInterface
{
    /**
     * @return array<string, array{
     *   label: string,
     *   user_managed: bool,
     *   credentials: array<string, array{input: string, label: string, required: bool, secret: bool, default?: string}>
     * }>
     */
    public function definitions(): array;

    /**
     * @param array<string, mixed> $input
     * @return array<string, string>|null
     */
    public function credentialsFromInput(string $type, array $input): ?array;

    /** @param array<string, mixed> $credentials */
    public function credentialsComplete(string $type, array $credentials): bool;
}

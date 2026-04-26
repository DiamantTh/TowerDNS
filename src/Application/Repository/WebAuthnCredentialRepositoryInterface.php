<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Repository;

use Webauthn\PublicKeyCredentialSource;

interface WebAuthnCredentialRepositoryInterface
{
    /**
     * Returns all credentials registered for a given user.
     *
     * @return list<array{credential_id: string, name: string, created_at: string, last_used_at: string|null, source: PublicKeyCredentialSource}>
     */
    public function findByUserId(string $userId): array;

    /**
     * Looks up a credential by its base64url-encoded credential ID.
     */
    public function findByCredentialId(string $credentialId): ?PublicKeyCredentialSource;

    /**
     * Persists a new credential for a user.
     */
    public function save(string $userId, string $name, PublicKeyCredentialSource $source): void;

    /**
     * Updates the counter and last-used timestamp after a successful assertion.
     */
    public function updateAfterAuthentication(string $credentialId, int $counter): void;

    /**
     * Deletes a specific credential that belongs to the given user.
     * The user_id constraint prevents cross-user deletion.
     */
    public function delete(string $credentialId, string $userId): void;
}

<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Repository;

use Webauthn\CredentialRecord;

interface WebAuthnCredentialRepositoryInterface
{
    /**
     * Returns all credentials registered for a given user.
     *
     * @return list<array{credential_id: string, name: string, created_at: string, last_used_at: string|null, attachment: string|null, aaguid: string, transports: list<string>, backup_eligible: bool|null, backup_state: bool|null, source: CredentialRecord}>
     */
    public function findByUserId(string $userId): array;

    /**
     * Looks up a credential by its base64url-encoded credential ID.
     */
    public function findByCredentialId(string $credentialId): ?CredentialRecord;

    /** Looks up a credential only when it belongs to the supplied user. */
    public function findByCredentialIdForUser(string $credentialId, string $userId): ?CredentialRecord;

    /** Returns the number of credentials stored by all users. */
    public function countAll(): int;

    /** Returns the number of credentials registered for one user. */
    public function countByUserId(string $userId): int;

    /**
     * Persists a new credential for a user.
     */
    public function save(string $userId, string $name, CredentialRecord $source, ?string $attachment = null, int $maxCredentials = 10): void;

    /** Allows only the recovery-scoped total ceiling while pre-recovery keys remain intact. */
    public function saveDuringRecovery(string $userId, string $name, CredentialRecord $source, ?string $attachment, int $recoveryCeiling): void;

    /**
     * Updates the counter and last-used timestamp after a successful assertion.
     */
    public function updateAfterAuthentication(CredentialRecord $source): void;

    /**
     * Deletes a specific credential that belongs to the given user.
     * The user_id constraint prevents cross-user deletion.
     */
    public function delete(string $credentialId, string $userId): void;
}

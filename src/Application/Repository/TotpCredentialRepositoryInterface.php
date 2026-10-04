<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Repository;

interface TotpCredentialRepositoryInterface
{
    /** @return list<array{id: string, label: string, created_at: string, last_used_at: string|null, secret_encrypted: string}> */
    public function findByUserId(string $userId): array;

    public function countByUserId(string $userId): int;

    public function save(string $id, string $userId, string $encryptedSecret, string $label, int $maxCredentials): void;

    public function markUsed(string $id, string $userId): void;

    public function delete(string $id, string $userId): void;
}

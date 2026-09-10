<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Domain\Auth;

final readonly class PasswordResetToken
{
    public function __construct(
        public int     $id,
        public string  $userId,
        public string  $tokenHash,
        public string  $createdAt,
        public string  $expiresAt,
        public ?string $usedAt = null,
    ) {}

    public function isExpired(): bool
    {
        return $this->expiresAt < new \DateTimeImmutable()->format('Y-m-d H:i:s');
    }

    public function isUsed(): bool
    {
        return $this->usedAt !== null;
    }

    public function isValid(): bool
    {
        return !$this->isExpired() && !$this->isUsed();
    }
}

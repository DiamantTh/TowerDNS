<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Repository;

interface StepUpProofNonceRepositoryInterface
{
    /**
     * Atomically claims a signed step-up proof nonce until it expires.
     *
     * @return bool false when the nonce has already been claimed
     */
    public function claim(string $nonce, int $expiresAt): bool;
}

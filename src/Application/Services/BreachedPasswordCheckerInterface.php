<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Services;

/**
 * Checks whether a password has appeared in known data breaches.
 *
 * Implementations MUST NEVER transmit the cleartext password.
 * Use k-anonymity / hashed lookups (e.g. HIBP Range API).
 */
interface BreachedPasswordCheckerInterface
{
    /**
     * Returns the number of times the given password has been seen in breach
     * corpora, or 0 if it is not known to be breached.
     *
     * Implementations SHOULD return 0 on transient errors (network, timeout)
     * when configured fail-open, to avoid locking users out.
     */
    public function timesSeen(string $password): int;
}

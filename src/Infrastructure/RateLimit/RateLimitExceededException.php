<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\RateLimit;

use TowerDNS\Application\Exception\ProviderRequestException;

/**
 * Thrown when a provider's rate limit is exceeded, either proactively by the
 * {@see RateLimiter} before a request or reactively from an HTTP 429 response.
 *
 * The {@see retryAfter()} value is derived from the provider's Retry-After
 * response header or from the remaining time in the current rate-limit window.
 * A value of 0 means the wait time is unknown.
 */
final class RateLimitExceededException extends ProviderRequestException
{
    public function __construct(
        string $provider,
        private readonly int $retryAfter = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $retryAfter > 0
                ? sprintf('%s: Rate-Limit erreicht. Bitte %d Sekunden warten.', $provider, $retryAfter)
                : sprintf('%s: Rate-Limit erreicht.', $provider),
            429,
            $previous,
        );
    }

    /** Seconds to wait before retrying (0 = unknown). */
    public function retryAfter(): int
    {
        return $this->retryAfter;
    }
}

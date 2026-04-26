<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\RateLimit;

use Psr\SimpleCache\CacheInterface;

/**
 * Fixed-window rate limiter backed by a PSR-16 cache.
 *
 * Tracks API hits for a named key within a rolling window. The window resets
 * automatically when the underlying cache entry expires.
 *
 * This implementation is not atomic — for a single-process PHP setup this is
 * acceptable, but concurrent requests from separate processes can briefly
 * exceed the configured limit. Adjust the window and limit generously when
 * deploying in multi-process environments.
 *
 * Usage example (deSEC free tier: 300 requests/hour):
 *   $limiter = new RateLimiter($cache, 'desec_' . substr(hash('sha256', $token), 0, 16), 300, 3600, 'deSEC');
 *   $limiter->hit(); // throws RateLimitExceededException when exhausted
 */
final readonly class RateLimiter
{
    private const string KEY_PREFIX = 'towerdns_rl_';

    public function __construct(
        private CacheInterface $cache,
        private string $key,
        private int $limit,
        private int $windowSeconds,
        private string $provider = '',
    ) {}

    /**
     * Record one API hit.
     *
     * @throws RateLimitExceededException when the per-window limit is reached.
     */
    public function hit(): void
    {
        $cacheKey = self::KEY_PREFIX . $this->key;

        /** @var array{count: int, reset_at: int}|null $data */
        $data = $this->cache->get($cacheKey);

        if ($data === null) {
            $this->cache->set($cacheKey, [
                'count'    => 1,
                'reset_at' => time() + $this->windowSeconds,
            ], $this->windowSeconds);
            return;
        }

        if ($data['count'] >= $this->limit) {
            $retryAfter = max(0, $data['reset_at'] - time());
            throw new RateLimitExceededException($this->provider, $retryAfter);
        }

        $remainingTtl = max(1, $data['reset_at'] - time());
        $this->cache->set($cacheKey, [
            'count'    => $data['count'] + 1,
            'reset_at' => $data['reset_at'],
        ], $remainingTtl);
    }

    /** How many hits remain in the current window. */
    public function remaining(): int
    {
        /** @var array{count: int, reset_at: int}|null $data */
        $data = $this->cache->get(self::KEY_PREFIX . $this->key);
        if ($data === null) {
            return $this->limit;
        }
        return max(0, $this->limit - $data['count']);
    }

    /** Unix timestamp when the current window resets (0 if not yet started). */
    public function resetAt(): int
    {
        /** @var array{count: int, reset_at: int}|null $data */
        $data = $this->cache->get(self::KEY_PREFIX . $this->key);
        return $data !== null ? $data['reset_at'] : 0;
    }
}

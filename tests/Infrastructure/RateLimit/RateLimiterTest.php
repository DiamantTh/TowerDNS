<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\RateLimit;

use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use TowerDNS\Infrastructure\RateLimit\RateLimiter;
use TowerDNS\Infrastructure\RateLimit\RateLimitExceededException;

/** @psalm-api Runtime discovery by PHPUnit or local module loading is not statically visible. */
final class RateLimiterTest extends TestCase
{
    public function testAllowsHitsWithinLimit(): void
    {
        $limiter = new RateLimiter($this->cache(), 'k1', 3, 60, 'Test');

        $limiter->hit();
        $limiter->hit();
        $limiter->hit();

        self::assertSame(0, $limiter->remaining());
    }

    public function testThrowsWhenLimitExceeded(): void
    {
        $limiter = new RateLimiter($this->cache(), 'k2', 1, 60, 'Test');

        $limiter->hit();

        $this->expectException(RateLimitExceededException::class);
        $limiter->hit();
    }

    public function testTracksKeysIndependently(): void
    {
        $cache   = $this->cache();
        $limiter = new RateLimiter($cache, 'user-a', 1, 60, 'Test');
        $other   = new RateLimiter($cache, 'user-b', 1, 60, 'Test');

        $limiter->hit();

        // A different key must not be affected by another key's usage.
        $other->hit();
        self::assertSame(0, $other->remaining());
    }

    private function cache(): CacheInterface
    {
        return new class implements CacheInterface {
            /** @var array<string, mixed> */
            private array $store = [];

            #[\Override]
            public function get(string $key, mixed $default = null): mixed
            {
                return $this->store[$key] ?? $default;
            }

            #[\Override]
            public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
            {
                $this->store[$key] = $value;
                return true;
            }

            #[\Override]
            public function delete(string $key): bool
            {
                unset($this->store[$key]);
                return true;
            }

            #[\Override]
            public function clear(): bool
            {
                $this->store = [];
                return true;
            }

            #[\Override]
            public function getMultiple(iterable $keys, mixed $default = null): iterable
            {
                $result = [];
                foreach ($keys as $key) {
                    $result[$key] = $this->get($key, $default);
                }
                return $result;
            }

            /** @param iterable<mixed, mixed> $values */
            #[\Override]
            public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
            {
                foreach ($values as $key => $value) {
                    $this->set((string) $key, $value, $ttl);
                }
                return true;
            }

            #[\Override]
            public function deleteMultiple(iterable $keys): bool
            {
                foreach ($keys as $key) {
                    $this->delete($key);
                }
                return true;
            }

            #[\Override]
            public function has(string $key): bool
            {
                return array_key_exists($key, $this->store);
            }
        };
    }
}

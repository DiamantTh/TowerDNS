<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Repository;

/**
 * Read/write access to the `system_settings` table.
 *
 * Keys use dot-notation (e.g. `security.password.min_length`).
 * Values are JSON-encoded scalars / arrays so types round-trip cleanly.
 *
 * Implementations SHOULD cache reads for the duration of a single request.
 */
interface SystemSettingsRepositoryInterface
{
    /**
     * Returns the stored value or `$default` when the key is unknown.
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Returns all settings as an associative array `key => value`.
     *
     * @return array<string, mixed>
     */
    public function getAll(): array;

    /**
     * Persists a single setting and records the actor in `updated_by`.
     */
    public function set(string $key, mixed $value, ?string $actorUserId): void;

    /**
     * Persists multiple settings atomically.
     *
     * @param array<string, mixed> $values
     */
    public function setMany(array $values, ?string $actorUserId): void;
}

<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use TowerDNS\Application\Repository\SystemSettingsRepositoryInterface;

/**
 * DBAL-backed system settings repository.
 *
 * Caches the full row set for the lifetime of the instance (= one HTTP
 * request in PHP-FPM, one CLI command for Symfony Console). Writes invalidate
 * the cache.
 */
final class DbalSystemSettingsRepository implements SystemSettingsRepositoryInterface
{
    /** @var array<string, mixed>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly Connection     $connection,
        private readonly ClockInterface $clock,
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->getAll();
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public function getAll(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT setting_key, setting_value FROM system_settings'
        );

        $out = [];
        foreach ($rows as $row) {
            $key = (string) $row['setting_key'];
            $raw = (string) $row['setting_value'];
            /** @var mixed $decoded */
            $decoded   = json_decode($raw, true);
            $out[$key] = json_last_error() === JSON_ERROR_NONE ? $decoded : $raw;
        }

        $this->cache = $out;
        return $out;
    }

    public function set(string $key, mixed $value, ?string $actorUserId): void
    {
        $this->setMany([$key => $value], $actorUserId);
    }

    public function setMany(array $values, ?string $actorUserId): void
    {
        if ($values === []) {
            return;
        }

        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $this->connection->transactional(function (Connection $conn) use ($values, $actorUserId, $now): void {
            foreach ($values as $key => $value) {
                $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($encoded === false) {
                    throw new \InvalidArgumentException('Setting "' . $key . '" is not JSON-encodable.');
                }

                $existing = $conn->fetchOne(
                    'SELECT setting_key FROM system_settings WHERE setting_key = ?',
                    [$key],
                );

                if ($existing === false) {
                    $conn->insert('system_settings', [
                        'setting_key'   => $key,
                        'setting_value' => $encoded,
                        'updated_at'    => $now,
                        'updated_by'    => $actorUserId,
                    ]);
                } else {
                    $conn->update(
                        'system_settings',
                        [
                            'setting_value' => $encoded,
                            'updated_at'    => $now,
                            'updated_by'    => $actorUserId,
                        ],
                        ['setting_key' => $key],
                    );
                }
            }
        });

        $this->cache = null;
    }
}

<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Repository;

interface MonitoringRepositoryInterface
{
    /** @return list<array<string, mixed>> */
    public function listEnabled(int $limit = 250, ?int $managedZoneId = null): array;

    /** @return list<array<string, mixed>> */
    public function overview(int $limit = 250): array;

    /** @return list<array<string, mixed>> */
    public function history(int $managedZoneId, int $limit = 50): array;

    public function configure(int $managedZoneId, bool $enabled, bool $smtpDaneEnabled, int $intervalMinutes, ?string $actorUserId, string $updatedAt): void;

    /** @param array<string, mixed> $summary */
    public function recordResult(int $managedZoneId, string $checkType, string $status, string $severity, string $method, array $summary, string $fingerprint, string $checkedAt, string $retentionCutoff): bool;
}

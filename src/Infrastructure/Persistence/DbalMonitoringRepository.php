<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use TowerDNS\Application\Repository\MonitoringRepositoryInterface;

/** DBAL persistence for opt-in monitor configuration, current state and transitions. */
final readonly class DbalMonitoringRepository implements MonitoringRepositoryInterface
{
    public function __construct(private Connection $connection) {}

    #[\Override]
    public function listEnabled(int $limit = 250, ?int $managedZoneId = null): array
    {
        $limit      = max(1, min(250, $limit));
        $zoneFilter = $managedZoneId === null ? '' : ' AND z.id = ?';
        $params     = $managedZoneId === null ? ['smtp_dane', true] : ['smtp_dane', true, $managedZoneId];
        return $this->connection->fetchAllAssociative(
            'SELECT a.tlsa_owner, a.tlsa_expected, z.id AS managed_zone_id, z.account_id, z.canonical_name, c.smtp_dane_enabled, c.interval_minutes, s.status, s.severity, s.method, s.summary_json, s.fingerprint, s.checked_at, s.changed_at '
            . 'FROM dns_monitoring_config c JOIN managed_zones z ON z.id = c.managed_zone_id '
            . 'LEFT JOIN dns_monitoring_state s ON s.managed_zone_id = z.id AND s.check_type = ? '
            . 'LEFT JOIN dns_monitoring_alerts a ON a.managed_zone_id = z.id '
            . 'WHERE c.enabled = ? AND c.smtp_dane_enabled = TRUE' . $zoneFilter . ' ORDER BY CASE WHEN s.checked_at IS NULL THEN 0 ELSE 1 END, s.checked_at ASC, z.id LIMIT ' . $limit,
            $params,
        );
    }

    #[\Override]
    public function overview(int $limit = 250): array
    {
        $limit = max(1, min(250, $limit));
        return $this->connection->fetchAllAssociative(
            'SELECT a.tlsa_owner, a.tlsa_expected, a.recipient, a.locale, a.minimum, a.confirmation_runs, a.muted_until, a.active_status, a.acknowledged_at, z.id AS managed_zone_id, z.account_id, z.provider_account_id, z.canonical_name, COALESCE(c.enabled, ?) AS enabled, COALESCE(c.smtp_dane_enabled, ?) AS smtp_dane_enabled, COALESCE(c.interval_minutes, ?) AS interval_minutes, s.status, s.severity, s.method, s.summary_json, s.checked_at, s.changed_at '
            . 'FROM managed_zones z LEFT JOIN dns_monitoring_config c ON z.id = c.managed_zone_id '
            . 'LEFT JOIN dns_monitoring_state s ON s.managed_zone_id = z.id AND s.check_type = ? '
            . 'LEFT JOIN dns_monitoring_alerts a ON a.managed_zone_id = z.id '
            . 'ORDER BY z.canonical_name, z.id LIMIT ' . $limit,
            [false, false, 60, 'smtp_dane'],
        );
    }

    #[\Override]
    public function history(int $managedZoneId, int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        return $this->connection->fetchAllAssociative(
            'SELECT check_type, previous_status, status, severity, fingerprint, occurred_at FROM dns_monitoring_events WHERE managed_zone_id = ? ORDER BY occurred_at DESC, id DESC LIMIT ' . $limit,
            [$managedZoneId],
        );
    }

    #[\Override]
    public function configure(int $managedZoneId, bool $enabled, bool $smtpDaneEnabled, int $intervalMinutes, ?string $actorUserId, string $updatedAt): void
    {
        if ($intervalMinutes < 5 || $intervalMinutes > 1440) {
            throw new \InvalidArgumentException('Monitoring interval must be between 5 and 1440 minutes.');
        }
        if ($this->connection->fetchOne('SELECT 1 FROM managed_zones WHERE id = ?', [$managedZoneId]) === false) {
            throw new \InvalidArgumentException('Managed zone does not exist.');
        }

        $exists = $this->connection->fetchOne('SELECT 1 FROM dns_monitoring_config WHERE managed_zone_id = ?', [$managedZoneId]);
        $data   = ['enabled' => $enabled, 'smtp_dane_enabled' => $smtpDaneEnabled, 'interval_minutes' => $intervalMinutes, 'updated_at' => $updatedAt, 'updated_by' => $actorUserId];
        if ($exists === false) {
            $this->connection->insert('dns_monitoring_config', ['managed_zone_id' => $managedZoneId, ...$data]);
            return;
        }
        $this->connection->update('dns_monitoring_config', $data, ['managed_zone_id' => $managedZoneId]);
    }

    #[\Override]
    public function recordResult(int $managedZoneId, string $checkType, string $status, string $severity, string $method, array $summary, string $fingerprint, string $checkedAt, string $retentionCutoff): bool
    {
        if ($checkType !== 'smtp_dane' || !in_array($severity, ['info', 'notice', 'warning', 'critical', 'unknown'], true)) {
            throw new \InvalidArgumentException('Unsupported monitoring result category.');
        }
        $json = json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($json) > 8192 || preg_match('/\A[0-9a-f]{64}\z/D', $fingerprint) !== 1) {
            throw new \InvalidArgumentException('Monitoring result exceeds its storage bounds.');
        }

        return $this->connection->transactional(function (Connection $connection) use ($managedZoneId, $checkType, $status, $severity, $method, $json, $fingerprint, $checkedAt, $retentionCutoff): bool {
            // A no-op write takes the config row lock on the supported SQL
            // platforms. Concurrent scheduler processes can perform network
            // checks in parallel, but serialize the read/transition/write so
            // the initial state and transition event cannot race each other.
            $connection->executeStatement(
                'UPDATE dns_monitoring_config SET updated_at = updated_at WHERE managed_zone_id = ?',
                [$managedZoneId],
            );

            if ($connection->fetchOne('SELECT 1 FROM dns_monitoring_config WHERE managed_zone_id = ? AND enabled = TRUE AND smtp_dane_enabled = TRUE', [$managedZoneId]) === false) {
                return false;
            }

            $previous = $connection->fetchAssociative('SELECT status, fingerprint, checked_at FROM dns_monitoring_state WHERE managed_zone_id = ? AND check_type = ?', [$managedZoneId, $checkType]);
            if ($previous !== false && (string) $previous['checked_at'] >= $checkedAt) {
                return false;
            }
            new MonitoringAlerts($connection)->observe($managedZoneId, $status, $severity, $method, $checkedAt);
            $changed = $previous === false || !hash_equals((string) $previous['fingerprint'], $fingerprint);
            if ($previous === false) {
                $connection->insert('dns_monitoring_state', [
                    'managed_zone_id' => $managedZoneId, 'check_type' => $checkType, 'status' => $status,
                    'severity'        => $severity, 'method' => $method, 'summary_json' => $json,
                    'fingerprint'     => $fingerprint, 'checked_at' => $checkedAt, 'changed_at' => $checkedAt,
                ]);
            } else {
                $connection->update('dns_monitoring_state', [
                    'status'       => $status, 'severity' => $severity, 'method' => $method,
                    'summary_json' => $json, 'fingerprint' => $fingerprint, 'checked_at' => $checkedAt,
                    ...($changed ? ['changed_at' => $checkedAt] : []),
                ], ['managed_zone_id' => $managedZoneId, 'check_type' => $checkType]);
            }
            if ($changed) {
                $connection->insert('dns_monitoring_events', [
                    'managed_zone_id' => $managedZoneId, 'check_type' => $checkType,
                    'previous_status' => $previous === false ? null : (string) $previous['status'],
                    'status'          => $status, 'severity' => $severity, 'fingerprint' => $fingerprint, 'occurred_at' => $checkedAt,
                ]);
            }
            $connection->executeStatement('DELETE FROM dns_monitoring_events WHERE occurred_at < ?', [$retentionCutoff]);
            return $changed;
        });
    }
}

<?php

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;

/** @psalm-api Wired through dependency injection. */
final readonly class MonitoringAlerts
{
    public function __construct(private Connection $db) {}

    public function settings(int $zone, ?string $recipient, string $locale, string $minimum, int $runs, int $muteHours, bool $acknowledge, string $owner = '', string $expected = ''): void
    {
        if (($recipient !== null && (strlen($recipient) > 254 || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\r\n]/', $recipient) === 1))
            || !in_array($locale, ['de-DE', 'en-GB'], true) || !in_array($minimum, ['warning', 'critical'], true)
            || $runs < 2 || $runs > 5 || $muteHours < 0 || $muteHours > 168) {
            throw new \InvalidArgumentException('Invalid notification settings.');
        }
        $owner    = strtolower(trim($owner));
        $expected = trim($expected);
        $parts    = [];
        if (($owner === '') !== ($expected === '') || strlen($owner) > 200 || strlen($expected) > 8192
                                                   || ($owner !== '' && preg_match('/\A_([1-9][0-9]{0,4})\._(?:tcp|udp|sctp)(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*\z/D', $owner, $parts) !== 1)
                                                   || (isset($parts[1]) && (int) $parts[1] > 65535)) {
            throw new \InvalidArgumentException('Invalid TLSA monitoring target.');
        }
        $values = $expected === '' ? [] : (preg_split('/\R/', $expected) ?: []);
        if (count($values) > 16) {
            throw new \InvalidArgumentException('Too many monitoring values.');
        }
        $type   = \TowerDNS\Domain\DNS\DNSRecordType::parse('TLSA');
        $values = array_map(static fn(string $line): string => \TowerDNS\Application\DNS\RdataCanonicalizer::canonicalize($type, $line), $values);
        $this->db->transactional(function () use ($zone, $recipient, $locale, $minimum, $runs, $muteHours, $acknowledge, $owner, $values): void {
            $this->db->executeStatement('UPDATE dns_monitoring_config SET updated_at = updated_at WHERE managed_zone_id = ?', [$zone]);
            if ($this->db->fetchOne('SELECT 1 FROM dns_monitoring_config WHERE managed_zone_id = ?', [$zone]) === false) {
                throw new \InvalidArgumentException('Configure monitoring for this zone first.');
            }
            $data = [
                'recipient'         => $recipient, 'locale' => $locale, 'minimum' => $minimum,
                'confirmation_runs' => $runs, 'tlsa_owner' => $owner === '' ? null : $owner, 'tlsa_expected' => $values === [] ? null : implode("\n", $values), 'muted_until' => $muteHours === 0 ? null : gmdate('Y-m-d H:i:s', time() + 3600 * $muteHours),
            ];
            $row = $this->db->fetchAssociative('SELECT active_status FROM dns_monitoring_alerts WHERE managed_zone_id = ?', [$zone]);
            if ($row === false) {
                $this->db->insert('dns_monitoring_alerts', ['managed_zone_id' => $zone, ...$data]);
            } else {
                if ($acknowledge && $row['active_status'] !== null) {
                    $data['acknowledged_at'] = gmdate('Y-m-d H:i:s');
                }
                $this->db->update('dns_monitoring_alerts', $data, ['managed_zone_id' => $zone]);
            }
        });
    }

    /** Called under the zone configuration lock, in the same transaction as the observation. */
    public function observe(int $zone, string $status, string $severity, string $method, string $at): void
    {
        $row = $this->db->fetchAssociative('SELECT * FROM dns_monitoring_alerts WHERE managed_zone_id = ?', [$zone]);
        if ($row === false) {
            $this->db->insert('dns_monitoring_alerts', ['managed_zone_id' => $zone]);
            $row = [];
        }
        $active    = $row['active_status'] ?? null;
        $healthy   = in_array($severity, ['info', 'notice'], true);
        $candidate = $healthy ? 'recovery' : $status;
        if ($status === 'not_checked' || ($active === 'dnssec_bogus' && $severity === 'unknown') || ($healthy && $active === null) || $active === $candidate) {
            $this->db->update('dns_monitoring_alerts', ['candidate' => null, 'candidate_runs' => 0, 'candidate_at' => null], ['managed_zone_id' => $zone]);
            return;
        }
        if (is_string($row['candidate_at'] ?? null) && $row['candidate_at'] >= new \DateTimeImmutable($at, new \DateTimeZone('UTC'))->modify('-60 seconds')->format('Y-m-d H:i:s')) {
            return;
        }
        $runs = ($row['candidate'] ?? null) === $candidate ? (int) $row['candidate_runs'] + 1 : 1;
        if ($runs < (int) ($row['confirmation_runs'] ?? 2)) {
            $this->db->update('dns_monitoring_alerts', ['candidate' => $candidate, 'candidate_runs' => $runs, 'candidate_at' => $at], ['managed_zone_id' => $zone]);
            return;
        }
        $this->db->update('dns_monitoring_alerts', [
            'active_status'   => $healthy ? null : $status, 'active_since' => $healthy ? null : $at,
            'acknowledged_at' => null, 'candidate' => null, 'candidate_runs' => 0, 'candidate_at' => null,
        ], ['managed_zone_id' => $zone]);
        $this->db->insert('dns_monitoring_events', [
            'managed_zone_id' => $zone, 'check_type' => 'confirmed_alert', 'previous_status' => $active,
            'status'          => $candidate, 'severity' => $severity, 'occurred_at' => $at,
            'fingerprint'     => hash('sha256', "{$zone}/{$candidate}/{$at}"),
        ]);
        if (is_string($row['recipient'] ?? null) && ($row['muted_until'] ?? '') <= $at
                                                 && ($healthy || $severity === 'critical' || $row['minimum'] === 'warning')) {
            $this->enqueue(hash('sha256', "{$zone}/{$candidate}/{$at}"), $zone, $candidate, $row['recipient'], (string) $row['locale'], $method, $at);
        }
    }

    /** @return array<string, mixed>|false */
    public function status(int $account, int $zone): array|false
    {
        return $this->db->fetchAssociative(
            "SELECT z.id AS managed_zone_id, z.canonical_name, c.enabled, c.smtp_dane_enabled, c.interval_minutes, s.status, s.severity, s.method, s.checked_at,
                a.active_status, a.active_since, a.acknowledged_at, a.muted_until
             FROM managed_zones z LEFT JOIN dns_monitoring_config c ON c.managed_zone_id = z.id
             LEFT JOIN dns_monitoring_alerts a ON a.managed_zone_id = z.id
             LEFT JOIN dns_monitoring_state s ON s.managed_zone_id = z.id AND s.check_type = 'smtp_dane'
             WHERE z.account_id = ? AND z.id = ?",
            [$account, $zone]
        );
    }

    /** @return list<array<string, mixed>> */
    public function page(int $zone, string $since, int $before, int $limit): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT id, check_type, previous_status, status, severity, occurred_at FROM dns_monitoring_events
             WHERE managed_zone_id = ? AND occurred_at >= ? AND id < ? ORDER BY id DESC LIMIT ' . max(1, min(51, $limit)),
            [$zone, $since, $before],
        );
    }

    /** @return list<array<string, mixed>> */
    public function deliveries(int $zone): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT kind, method, status, failure, attempts, occurred_at, next_attempt_at FROM dns_monitoring_deliveries
             WHERE managed_zone_id = ? ORDER BY occurred_at DESC, event_key LIMIT 20',
            [$zone]
        );
    }

    private function enqueue(string $key, int $zone, string $kind, string $recipient, string $locale, string $method, string $at, ?int $apiKey = null): void
    {
        $this->db->insert('dns_monitoring_deliveries', [
            'event_key'       => $key, 'managed_zone_id' => $zone, 'kind' => $kind, 'recipient' => $recipient,
            'locale'          => $locale, 'method' => $method, 'status' => 'pending', 'occurred_at' => $at,
            'next_attempt_at' => $at, 'api_key_id' => $apiKey,
        ]);
    }

    public function queueExpiringKeys(string $now, string $until): void
    {
        $rows = $this->db->fetchAllAssociative(
            "SELECT k.id, k.scope_zone, a.recipient, a.locale FROM api_keys k
            JOIN managed_zones z ON z.id = k.scope_zone AND z.account_id = k.scope_account
            JOIN dns_monitoring_config c ON c.managed_zone_id = z.id
            JOIN dns_monitoring_alerts a ON a.managed_zone_id = z.id
            LEFT JOIN dns_monitoring_deliveries d ON d.api_key_id = k.id
            WHERE c.enabled = TRUE AND a.recipient IS NOT NULL AND a.minimum = 'warning' AND (a.muted_until IS NULL OR a.muted_until <= ?)
              AND k.is_active = TRUE AND k.expires_at > ? AND k.expires_at <= ? AND d.event_key IS NULL
            ORDER BY k.expires_at, k.id LIMIT 25",
            [$now, $now, $until]
        );
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            try {
                $this->enqueue(hash('sha256', 'key-expiry/' . $id), (int) $row['scope_zone'], 'api_key_expiring', (string) $row['recipient'], (string) $row['locale'], 'local_key_metadata', $now, $id);
            } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
                // Another dispatcher already queued this key.
            }
        }
    }

    /** @return list<array<string, mixed>> */
    public function due(string $now): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT d.*, z.canonical_name, a.recipient AS current_recipient, a.muted_until, c.enabled, k.expires_at,
                CASE WHEN k.is_active = TRUE AND k.expires_at > ? THEN 1 ELSE 0 END AS active_key
            FROM dns_monitoring_deliveries d JOIN managed_zones z ON z.id = d.managed_zone_id
            JOIN dns_monitoring_config c ON c.managed_zone_id = z.id
            JOIN dns_monitoring_alerts a ON a.managed_zone_id = z.id
            LEFT JOIN api_keys k ON k.id = d.api_key_id
            WHERE d.status = 'pending' AND d.next_attempt_at <= ? ORDER BY d.next_attempt_at, d.event_key LIMIT 20",
            [$now, $now]
        );
    }

    public function claim(string $key, string $now): bool
    {
        return $this->db->executeStatement(
            "UPDATE dns_monitoring_deliveries SET status = 'sending', attempts = attempts + 1, next_attempt_at = ?
             WHERE event_key = ? AND status = 'pending' AND next_attempt_at <= ?",
            [$now, $key, $now]
        ) === 1;
    }

    /** @phpstan-impure Consent and revocation can change in another process between checks. */
    public function canDeliver(string $key, string $now): bool
    {
        $delivery = $this->db->fetchAssociative(
            "SELECT d.recipient, a.recipient AS current_recipient FROM dns_monitoring_deliveries d
             JOIN dns_monitoring_config c ON c.managed_zone_id = d.managed_zone_id
             JOIN dns_monitoring_alerts a ON a.managed_zone_id = d.managed_zone_id
             LEFT JOIN api_keys k ON k.id = d.api_key_id
             WHERE d.event_key = ? AND d.status = 'sending' AND c.enabled = TRUE
               AND (a.muted_until IS NULL OR a.muted_until <= ?)
               AND ((d.api_key_id IS NULL AND c.smtp_dane_enabled = TRUE)
                 OR (d.api_key_id IS NOT NULL AND k.is_active = TRUE AND k.expires_at > ?))",
            [$key, $now, $now],
        );
        return $delivery !== false && $delivery['recipient'] === $delivery['current_recipient'];
    }

    public function finish(string $key, string $status, ?string $failure, string $next): void
    {
        $this->db->update('dns_monitoring_deliveries', ['status' => $status, 'failure' => $failure, 'next_attempt_at' => $next], ['event_key' => $key, 'status' => 'sending']);
    }

    public function cleanup(string $abandoned, string $cutoff): void
    {
        $this->db->executeStatement("UPDATE dns_monitoring_deliveries SET status = 'unknown', failure = 'interrupted' WHERE status = 'sending' AND next_attempt_at < ?", [$abandoned]);
        $this->db->executeStatement('DELETE FROM dns_monitoring_deliveries WHERE occurred_at < ?', [$cutoff]);
    }
}

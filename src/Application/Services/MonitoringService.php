<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Services;

use Psr\Clock\ClockInterface;
use TowerDNS\Application\Contracts\DnssecDiagnosticInterface;
use TowerDNS\Application\Repository\MonitoringRepositoryInterface;

/** Bounded, opt-in, read-only DNS monitoring; it never changes provider data. */
final readonly class MonitoringService
{
    public function __construct(
        private MonitoringRepositoryInterface $repository,
        private DnssecDiagnosticInterface $diagnostic,
        private ClockInterface $clock,
        private ?\TowerDNS\Application\Contracts\AuthoritativeTlsaCheckerInterface $authoritative = null,
    ) {}

    public function configure(int $zoneId, bool $enabled, bool $smtpDaneEnabled, int $intervalMinutes, ?string $actorId): void
    {
        $this->repository->configure($zoneId, $enabled, $smtpDaneEnabled, $intervalMinutes, $actorId, $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
    }

    /** @return array{checked: int, changed: int, warnings: int, failures: int, results: list<array<string, mixed>>} */
    public function runDue(?int $zoneId = null, bool $force = false): array
    {
        $now      = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $checked  = 0;
        $changed  = 0;
        $warnings = 0;
        $failures = 0;
        $results  = [];

        foreach ($this->repository->listEnabled(250, $zoneId) as $zone) {
            if (!$this->isTruthy($zone['smtp_dane_enabled'] ?? false)) {
                continue;
            }
            $lastChecked = $zone['checked_at'] ?? null;
            $interval    = max(5, min(1440, (int) ($zone['interval_minutes'] ?? 60)));
            if (!$force && is_string($lastChecked) && $lastChecked !== '') {
                try {
                    if (new \DateTimeImmutable($lastChecked, new \DateTimeZone('UTC')) > $now->modify('-' . $interval . ' minutes')) {
                        continue;
                    }
                } catch (\Exception) {
                    // Invalid historical timestamps make the check due; they do not block monitoring.
                }
            }

            ++$checked;
            $result = $this->checkZone($zone, $now);
            $changed  += $result['changed'] ? 1 : 0;
            $warnings += in_array($result['severity'], ['notice', 'warning', 'critical'], true) ? 1 : 0;
            $failures += $result['severity'] === 'unknown' ? 1 : 0;
            unset($result['changed']);
            $results[] = $result;
        }

        return ['checked' => $checked, 'changed' => $changed, 'warnings' => $warnings, 'failures' => $failures, 'results' => $results];
    }

    /** @return list<array<string, mixed>> */
    public function overview(int $limit = 250): array
    {
        return $this->repository->overview($limit);
    }

    /** @return list<array<string, mixed>> */
    public function history(int $zoneId, int $limit = 50): array
    {
        return $this->repository->history($zoneId, $limit);
    }

    /**
     * @param array<string, mixed> $zone
     * @return array{zone_id: int, zone: string, check_type: string, status: string, severity: string, method: string, checked_at: string, changed: bool}
     */
    private function checkZone(array $zone, \DateTimeImmutable $now): array
    {
        $zoneId  = (int) $zone['managed_zone_id'];
        $checked = $now->format('Y-m-d H:i:s');
        $domain  = (string) $zone['canonical_name'];
        try {
            $raw    = $this->diagnostic->checkSmtpDane($domain);
            $status = match ($raw['status'] ?? null) {
                'secure'      => $this->smtpRecordStatus($raw),
                'insecure'    => 'dnssec_insecure',
                'bogus'       => 'dnssec_bogus',
                'not_checked' => 'not_checked',
                default       => 'indeterminate',
            };
            $severity = match ($status) {
                'dnssec_bogus'                                => 'critical',
                'dnssec_insecure', 'published_without_dnssec' => 'warning',
                'secure_records_published'                    => 'info',
                'secure_partial_tlsa'                         => 'warning',
                'secure_no_mx', 'secure_no_tlsa'              => 'notice',
                default                                       => 'unknown',
            };
            $mx = [];
            foreach (is_array($raw['mx'] ?? null) ? array_slice($raw['mx'], 0, 4) : [] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $mx[] = [
                    'preference'  => is_int($item['preference'] ?? null) ? $item['preference'] : null,
                    'host'        => is_string($item['host'] ?? null) ? $item['host'] : '',
                    'dnssec'      => is_string($item['dnssec'] ?? null) ? $item['dnssec'] : 'indeterminate',
                    'tlsa_status' => is_string($item['tlsa_status'] ?? null) ? $item['tlsa_status'] : 'unknown',
                    'ttl'         => is_int($item['ttl'] ?? null) ? $item['ttl'] : null,
                ];
            }
            $summary = [
                'scope'           => 'dns_only_no_smtp_handshake',
                'resolver_status' => is_string($raw['status'] ?? null) ? $raw['status'] : 'indeterminate',
                'mx_dnssec'       => is_string($raw['mx_dnssec'] ?? null) ? $raw['mx_dnssec'] : 'indeterminate',
                'mx'              => $mx,
                'incomplete'      => ($raw['incomplete'] ?? false) === true,
            ];
            $method = is_string($raw['method'] ?? null) ? $raw['method'] : 'configured_validating_resolver';
        } catch (\Throwable) {
            $status   = 'check_execution_failed';
            $severity = 'unknown';
            $method   = 'configured_validating_resolver';
            $summary  = ['scope' => 'dns_only_no_smtp_handshake', 'reason' => 'diagnostic_request_failed'];
        }

        if ($this->authoritative instanceof \TowerDNS\Application\Contracts\AuthoritativeTlsaCheckerInterface && is_string($zone['tlsa_owner'] ?? null) && is_string($zone['tlsa_expected'] ?? null)) {
            $owner    = $zone['tlsa_owner'] . '.' . rtrim($domain, '.');
            $expected = explode("\n", $zone['tlsa_expected']);
            try {
                $result   = $this->authoritative->check($owner, $domain, $expected, null);
                $coverage = [];
                foreach ($result->servers as $server) {
                    if ($server['authoritative'] && in_array($server['status'], ['matches', 'has_unexpected_records', 'partial', 'mismatch', 'nodata', 'nxdomain'], true)) {
                        $coverage[] = array_diff($expected, $server['records']) === [];
                    }
                }
                $summary['authoritative'] = [
                    'owner'    => $owner, 'source' => $result->scope, 'status' => $result->status,
                    'answered' => count($coverage), 'matched' => count(array_filter($coverage)),
                ];
                if ($status !== 'dnssec_bogus' && in_array(false, $coverage, true)) {
                    $status   = in_array(true, $coverage, true) ? 'authoritative_divergent' : 'authoritative_missing';
                    $severity = 'warning';
                    $method   = $result->scope;
                } elseif ($severity !== 'critical' && ($coverage === [] || count($coverage) !== count($result->servers) || $result->status === 'incomplete')) {
                    $status   = 'authoritative_unknown';
                    $severity = 'unknown';
                    $method   = $result->scope;
                }
            } catch (\RuntimeException | \InvalidArgumentException) {
                $summary['authoritative'] = ['owner' => $owner, 'status' => 'check_execution_failed'];
                if ($severity !== 'critical') {
                    $status   = 'authoritative_unknown';
                    $severity = 'unknown';
                    $method   = 'direct_authoritative_nameserver_queries';
                }
            }
        }

        $fingerprint = hash('sha256', json_encode([$status, $severity, $method, $summary], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $changed     = $this->repository->recordResult($zoneId, 'smtp_dane', $status, $severity, $method, $summary, $fingerprint, $checked, $now->modify('-180 days')->format('Y-m-d H:i:s'));
        return ['zone_id' => $zoneId, 'zone' => $domain, 'check_type' => 'smtp_dane', 'status' => $status, 'severity' => $severity, 'method' => $method, 'checked_at' => $now->format(DATE_ATOM), 'changed' => $changed];
    }

    /** @param array<string, mixed> $result */
    private function smtpRecordStatus(array $result): string
    {
        $mx = is_array($result['mx'] ?? null) ? $result['mx'] : [];
        if ($mx === []) {
            return 'secure_no_mx';
        }
        $hasPublished = false;
        $hasMissing   = false;
        foreach ($mx as $record) {
            if (!is_array($record) || ($record['host'] ?? '') === '') {
                continue;
            }
            if (($record['tlsa_status'] ?? null) === 'published') {
                $hasPublished = true;
                if (($record['dnssec'] ?? null) !== 'secure') {
                    return 'published_without_dnssec';
                }
            } elseif (($record['tlsa_status'] ?? null) === 'no_tlsa_records') {
                $hasMissing = true;
            }
        }
        if ($hasPublished && $hasMissing) {
            return 'secure_partial_tlsa';
        }

        if ($hasMissing) {
            return 'secure_no_tlsa';
        }

        return $hasPublished ? 'secure_records_published' : 'secure_no_mx';
    }

    private function isTruthy(mixed $value): bool
    {
        return in_array($value, [true, 1, '1'], true);
    }
}

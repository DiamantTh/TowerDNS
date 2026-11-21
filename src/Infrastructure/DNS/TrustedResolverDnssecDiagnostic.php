<?php

declare(strict_types=1);

namespace TowerDNS\Infrastructure\DNS;

use GuzzleHttp\Client;
use TowerDNS\Application\Contracts\DnssecDiagnosticInterface;
use TowerDNS\Application\DTO\DnssecValidationResult;

/**
 * Delegates DNSSEC validation to an explicitly configured DNSSEC-validating
 * resolver reached over verified HTTPS. TowerDNS does not trust arbitrary AD
 * bits from the system resolver or a resolver supplied in an HTTP request.
 */
final readonly class TrustedResolverDnssecDiagnostic implements DnssecDiagnosticInterface
{
    /** @param null|\Closure(string, int, int): string $queryFunction Test seam returning a DNS wire response. */
    public function __construct(private string $endpoint = '', private ?\Closure $queryFunction = null) {}

    #[\Override]
    public function validateTlsa(string $ownerFqdn, string $zoneName): DnssecValidationResult
    {
        $checkedAt = new \DateTimeImmutable()->format(DATE_ATOM);
        if (!$this->configured()) {
            return new DnssecValidationResult('not_checked', $checkedAt, 'none', null, 'resolver_not_configured');
        }

        try {
            $tlsa = $this->query($ownerFqdn, 52);
            $ds   = $this->query($zoneName, 43);
        } catch (\Throwable) {
            return new DnssecValidationResult('indeterminate', $checkedAt, 'trusted_https_resolver', $this->resolverHost(), 'resolver_unavailable_or_invalid_response');
        }

        $ede = $this->dnssecBogusEde($tlsa->edeCodes) ?? $this->dnssecBogusEde($ds->edeCodes);
        if ($ede !== null || ($tlsa->rcode === 2 && in_array(6, $tlsa->edeCodes, true))) {
            return new DnssecValidationResult('bogus', $checkedAt, 'trusted_https_resolver', $this->resolverHost(), 'resolver_reports_dnssec_validation_failure', $ede ?? 6);
        }
        if (in_array($tlsa->rcode, [0, 3], true) && $tlsa->authenticatedData) {
            return new DnssecValidationResult('secure', $checkedAt, 'trusted_https_resolver_ad', $this->resolverHost(), 'tlsa_answer_authenticated_by_configured_resolver');
        }

        $hasDs = array_filter($ds->answers, static fn(array $record): bool => $record['type'] === 43 && $record['owner'] === strtolower(rtrim($zoneName, '.')));
        if ($tlsa->rcode === 0 && $ds->rcode === 0 && $hasDs === [] && $ds->authenticatedData) {
            return new DnssecValidationResult('insecure', $checkedAt, 'trusted_https_resolver_authenticated_ds_denial', $this->resolverHost(), 'authenticated_absence_of_parent_ds');
        }

        $reason = $tlsa->rcode === 2 || $ds->rcode === 2
            ? 'resolver_servfail_without_dnssec_bogus_proof'
            : 'resolver_did_not_provide_authenticated_result';
        return new DnssecValidationResult('indeterminate', $checkedAt, 'trusted_https_resolver', $this->resolverHost(), $reason);
    }

    /** @return array<string, mixed> */
    #[\Override]
    public function checkSmtpDane(string $domain): array
    {
        $checkedAt = new \DateTimeImmutable()->format(DATE_ATOM);
        $deadline  = microtime(true) + 6.0;
        if (!$this->configured()) {
            return ['status' => 'not_checked', 'checked_at' => $checkedAt, 'method' => 'none', 'mx' => [], 'dane' => 'not_tested', 'reason' => 'resolver_not_configured'];
        }

        try {
            $mxResponse = $this->query($domain, 15);
        } catch (\Throwable) {
            return ['status' => 'indeterminate', 'checked_at' => $checkedAt, 'method' => 'trusted_https_resolver', 'mx' => [], 'dane' => 'not_tested', 'reason' => 'resolver_unavailable_or_invalid_response'];
        }
        if ($mxResponse->rcode !== 0) {
            return ['status' => $this->responseStatus($mxResponse), 'checked_at' => $checkedAt, 'method' => 'trusted_https_resolver', 'mx' => [], 'dane' => 'not_tested', 'reason' => 'mx_lookup_failed'];
        }

        /** @var array<string, array{preference: int, host: string, ttl: int, tlsa_records?: list<string>, dnssec?: string, tlsa_status?: string, tlsa_rcode?: int|null}> $mxRecords */
        $mxRecords = [];
        foreach ($mxResponse->answers as $answer) {
            if ($answer['type'] !== 15 || $answer['owner'] !== strtolower(rtrim($domain, '.'))) {
                continue;
            }
            $mx                                                   = DnsWireCodec::parseMxRdata($mxResponse->packet, $answer['offset'], strlen($answer['rdata']));
            $mxRecords[$mx['preference'] . '|' . $mx['exchange']] = ['preference' => $mx['preference'], 'host' => $mx['exchange'], 'ttl' => $answer['ttl']];
        }
        $mxRecords = array_values($mxRecords);
        usort($mxRecords, static fn(array $a, array $b): int => [$a['preference'], $a['host']] <=> [$b['preference'], $b['host']]);
        $truncated = count($mxRecords) > 4;
        if ($truncated) {
            $mxRecords = array_slice($mxRecords, 0, 4);
        }
        foreach ($mxRecords as &$mxRecord) {
            if (microtime(true) >= $deadline) {
                $mxRecord['tlsa_records'] = [];
                $mxRecord['dnssec']       = 'not_checked';
                $mxRecord['tlsa_status']  = 'lookup_skipped_deadline';
                continue;
            }
            try {
                if ($mxRecord['host'] === '') {
                    $mxRecord['tlsa_records'] = [];
                    $mxRecord['dnssec']       = 'not_applicable';
                    $mxRecord['tlsa_status']  = 'null_mx';
                    continue;
                }
                $tlsa                     = $this->query('_25._tcp.' . $mxRecord['host'], 52);
                $mxRecord['tlsa_records'] = array_values(array_map(static fn(array $record): string => self::tlsaPresentation($record['rdata']), array_filter($tlsa->answers, static fn(array $record): bool => $record['type'] === 52 && $record['owner'] === '_25._tcp.' . $mxRecord['host'])));
                $mxRecord['dnssec']       = $this->responseStatus($tlsa);
                $mxRecord['tlsa_status']  = $mxRecord['tlsa_records'] === [] ? 'no_tlsa_records' : 'published';
                $mxRecord['tlsa_rcode']   = $tlsa->rcode;
            } catch (\Throwable) {
                $mxRecord['tlsa_records'] = [];
                $mxRecord['dnssec']       = 'indeterminate';
                $mxRecord['tlsa_rcode']   = null;
            }
        }
        unset($mxRecord);

        $status = !$mxResponse->authenticatedData || $truncated || microtime(true) >= $deadline ? 'indeterminate' : 'secure';
        foreach ($mxRecords as $mxRecord) {
            $mxDnssecStatus = $mxRecord['dnssec'] ?? 'indeterminate';
            if ($mxDnssecStatus === 'not_applicable') {
                continue;
            }
            if ($mxDnssecStatus !== 'secure') {
                $status = $mxDnssecStatus === 'bogus' ? 'bogus' : 'indeterminate';
                break;
            }
        }

        return [
            'status'     => $status,
            'checked_at' => $checkedAt,
            'method'     => 'trusted_https_resolver',
            'resolver'   => $this->resolverHost(),
            'mx_dnssec'  => $this->responseStatus($mxResponse),
            'mx'         => $mxRecords,
            'incomplete' => $truncated || microtime(true) >= $deadline,
            'dane'       => 'not_tested',
            'smtp_tls'   => 'not_tested',
            'scope'      => 'dns_only_no_smtp_handshake',
        ];
    }

    private function query(string $name, int $type): DnssecWireResponse
    {
        $id      = random_int(1, 65535);
        $request = DnsWireCodec::dnssecQuery($name, $id, $type);
        $packet  = $this->queryFunction instanceof \Closure
            ? ($this->queryFunction)($name, $type, $id)
            : $this->httpsQuery($request);

        return DnsWireCodec::parseDnssecResponse($packet, $id, $name, $type);
    }

    private function httpsQuery(string $packet): string
    {
        $client   = new Client(['timeout' => 1.5, 'connect_timeout' => 0.8, 'allow_redirects' => false, 'http_errors' => false, 'verify' => true]);
        $response = $client->post($this->endpoint, [
            'headers' => ['Accept' => 'application/dns-message', 'Content-Type' => 'application/dns-message'],
            'body'    => $packet,
        ]);
        if ($response->getStatusCode() !== 200 || !str_starts_with(strtolower($response->getHeaderLine('Content-Type')), 'application/dns-message')) {
            throw new \RuntimeException('Configured DNSSEC resolver returned an invalid response.');
        }
        $body = (string) $response->getBody();
        if ($body === '' || strlen($body) > 65535) {
            throw new \RuntimeException('Configured DNSSEC resolver response size is invalid.');
        }

        return $body;
    }

    private function configured(): bool
    {
        if ($this->queryFunction instanceof \Closure) {
            return true;
        }
        $parts = parse_url($this->endpoint);
        if (!is_array($parts)) {
            return false;
        }
        $scheme = $parts['scheme'] ?? null;
        $host   = $parts['host']   ?? null;
        return is_string($scheme) && strtolower($scheme) === 'https'
                                  && is_string($host) && $host !== ''
                                  && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['fragment']);
    }

    private function resolverHost(): ?string
    {
        $host = parse_url($this->endpoint, PHP_URL_HOST);
        return is_string($host) ? strtolower($host) : null;
    }

    private function responseStatus(DnssecWireResponse $response): string
    {
        if ($this->dnssecBogusEde($response->edeCodes) !== null) {
            return 'bogus';
        }
        if (in_array($response->rcode, [0, 3], true) && $response->authenticatedData) {
            return 'secure';
        }

        return 'indeterminate';
    }

    /** @param list<int> $edeCodes */
    private function dnssecBogusEde(array $edeCodes): ?int
    {
        foreach ($edeCodes as $code) {
            if (in_array($code, [6, 7, 8, 9, 10, 11, 12], true)) {
                return $code;
            }
        }

        return null;
    }

    private static function tlsaPresentation(string $rdata): string
    {
        if (strlen($rdata) < 4) {
            throw new \UnexpectedValueException('TLSA response contains malformed RDATA.');
        }

        return ord($rdata[0]) . ' ' . ord($rdata[1]) . ' ' . ord($rdata[2]) . ' ' . bin2hex(substr($rdata, 3));
    }
}

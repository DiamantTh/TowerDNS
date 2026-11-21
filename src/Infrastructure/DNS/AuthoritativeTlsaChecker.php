<?php

declare(strict_types=1);

namespace TowerDNS\Infrastructure\DNS;

use TowerDNS\Application\Contracts\AuthoritativeTlsaCheckerInterface;
use TowerDNS\Application\DNS\RdataCanonicalizer;
use TowerDNS\Application\DTO\AuthoritativeTlsaCheck;
use TowerDNS\Domain\DNS\DNSRecordType;

/** Queries the discovered public authoritative nameserver addresses directly; this does not validate DNSSEC. */
final readonly class AuthoritativeTlsaChecker implements AuthoritativeTlsaCheckerInterface
{
    private const int MAX_NAMESERVERS = 8;
    private const int MAX_ENDPOINTS   = 8;
    private const float TOTAL_TIMEOUT = 5.0;

    /**
     * @param null|\Closure(string, int): list<array<string, mixed>> $recordLookup Test seam for DNS discovery.
     * @param null|\Closure(string, string, float): DnsWireResponse $queryFunction Test seam for authoritative queries.
     */
    public function __construct(private ?\Closure $recordLookup = null, private ?\Closure $queryFunction = null) {}

    #[\Override]
    public function check(string $ownerFqdn, string $zoneName, array $expected, ?int $expectedTtl): AuthoritativeTlsaCheck
    {
        $owner = strtolower(rtrim($ownerFqdn, '.'));
        if ($owner === '' || strlen($owner) > 253) {
            throw new \InvalidArgumentException('Invalid TLSA owner name.');
        }
        $expected = array_values(array_unique(array_map(
            static fn(string $value): string => RdataCanonicalizer::canonicalize(DNSRecordType::parse('TLSA'), $value),
            $expected,
        )));
        sort($expected, SORT_STRING);
        $checkedAt = new \DateTimeImmutable()->format(DATE_ATOM);

        $zone      = strtolower(rtrim($zoneName, '.'));
        $nsRecords = $this->dnsGetRecords($zone, DNS_NS);
        $targets   = [];
        foreach ($nsRecords as $record) {
            if (is_string($record['target'] ?? null)) {
                $targets[] = strtolower(rtrim($record['target'], '.'));
            }
        }
        $targets             = array_values(array_unique($targets));
        $discoveryIncomplete = count($targets) > self::MAX_NAMESERVERS;
        $targets             = array_slice($targets, 0, self::MAX_NAMESERVERS);
        if ($targets === []) {
            return new AuthoritativeTlsaCheck($owner, 'discovery_failed', $checkedAt, 'system_resolver_unvalidated', $expected, [], $expectedTtl, 'direct_authoritative_nameserver_queries');
        }

        $endpoints       = [];
        $resolvedTargets = [];
        foreach ($targets as $target) {
            foreach ($this->dnsGetRecords($target, DNS_A | DNS_AAAA) as $record) {
                $address = is_string($record['ip'] ?? null) ? $record['ip'] : (is_string($record['ipv6'] ?? null) ? $record['ipv6'] : null);
                if (is_string($address) && $this->isPublicAddress($address)) {
                    $endpoints[$target . '|' . $address] = [$target, $address];
                    $resolvedTargets[$target]            = true;
                }
            }
        }
        foreach ($targets as $target) {
            if (!isset($resolvedTargets[$target])) {
                $discoveryIncomplete = true;
            }
        }
        $discoveryIncomplete = $discoveryIncomplete || count($endpoints) > self::MAX_ENDPOINTS;
        $endpoints           = array_slice(array_values($endpoints), 0, self::MAX_ENDPOINTS);
        if ($endpoints === []) {
            return new AuthoritativeTlsaCheck($owner, 'discovery_failed', $checkedAt, 'system_resolver_unvalidated', $expected, [], $expectedTtl, 'direct_authoritative_nameserver_queries');
        }

        $deadline = microtime(true) + self::TOTAL_TIMEOUT;
        $servers  = [];
        foreach ($endpoints as [$target, $address]) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $serverCheckedAt = new \DateTimeImmutable()->format(DATE_ATOM);
            try {
                $remaining = max(0.1, min(0.8, $deadline - microtime(true)));
                $response  = $this->query($address, $owner, $remaining);
                $status    = match (true) {
                    $response->rcode === 3 && $response->authoritative          => 'nxdomain',
                    $response->rcode !== 0                                      => 'dns_error',
                    !$response->authoritative                                   => 'not_authoritative',
                    $response->tlsaRecords                        === []        => 'nodata',
                    $response->tlsaRecords                        === $expected => 'matches',
                    array_diff($expected, $response->tlsaRecords) === []        => 'has_unexpected_records',
                    array_intersect($expected, $response->tlsaRecords) !== []   => 'partial',
                    default                                                     => 'mismatch',
                };
                $servers[] = [
                    'nameserver' => $target, 'address' => $address, 'status' => $status,
                    'rcode'      => $this->rcodeName($response->rcode), 'authoritative' => $response->authoritative,
                    'records'    => $response->tlsaRecords, 'minimum_ttl' => $response->minimumTtl, 'checked_at' => $serverCheckedAt,
                ];
            } catch (\RuntimeException | \UnexpectedValueException | \InvalidArgumentException) {
                $servers[] = [
                    'nameserver' => $target, 'address' => $address, 'status' => 'transport_error',
                    'rcode'      => null, 'authoritative' => false, 'records' => [], 'minimum_ttl' => null, 'checked_at' => $serverCheckedAt,
                ];
            }
        }

        $statuses = array_column($servers, 'status');
        if ($statuses === []) {
            $status = 'timeout';
        } elseif ($discoveryIncomplete || in_array('transport_error', $statuses, true)) {
            $status = 'incomplete';
        } elseif (count(array_filter($statuses, static fn(string $item): bool => $item === 'matches')) === count($statuses)) {
            $status = 'all_match';
        } elseif ($expected === [] && count(array_filter($statuses, static fn(string $item): bool => $item === 'nodata')) === count($statuses)) {
            $status = 'all_empty';
        } elseif (count(array_filter($statuses, static fn(string $item): bool => $item === 'nxdomain')) === count($statuses)) {
            $status = 'nxdomain';
        } elseif (in_array('matches', $statuses, true) || in_array('nxdomain', $statuses, true)) {
            $status = 'nameserver_disagreement';
        } elseif (count(array_filter($statuses, static fn(string $item): bool => $item === 'dns_error')) === count($statuses)) {
            $status = 'dns_error';
        } elseif (in_array('partial', $statuses, true) || in_array('nodata', $statuses, true) || in_array('mismatch', $statuses, true) || in_array('has_unexpected_records', $statuses, true)) {
            $status = 'records_differ';
        } else {
            $status = 'incomplete';
        }
        return new AuthoritativeTlsaCheck($owner, $status, $checkedAt, 'system_resolver_unvalidated', $expected, $servers, $expectedTtl, 'direct_authoritative_nameserver_queries');
    }

    /** @return list<array<string, mixed>> */
    private function dnsGetRecords(string $name, int $type): array
    {
        if ($this->recordLookup instanceof \Closure) {
            return ($this->recordLookup)($name, $type);
        }

        set_error_handler(static fn(): bool => true);
        try {
            $records = dns_get_record($name, $type);
        } catch (\Throwable) {
            return [];
        } finally {
            restore_error_handler();
        }
        return is_array($records) ? $records : [];
    }

    private function isPublicAddress(string $address): bool
    {
        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    private function query(string $address, string $owner, float $timeout): DnsWireResponse
    {
        if ($this->queryFunction instanceof \Closure) {
            return ($this->queryFunction)($address, $owner, $timeout);
        }

        $id     = random_int(0, 65535);
        $packet = DnsWireCodec::query($owner, $id);
        $host   = str_contains($address, ':') ? '[' . $address . ']' : $address;
        $errno  = 0;
        $error  = '';
        $stream = stream_socket_client('udp://' . $host . ':53', $errno, $error, $timeout, STREAM_CLIENT_CONNECT);
        if (!is_resource($stream)) {
            throw new \RuntimeException('DNS UDP transport unavailable.');
        }
        $wholeSeconds = (float) (int) $timeout;
        $microseconds = (int) (($timeout - $wholeSeconds) * 1_000_000.0);
        stream_set_timeout($stream, (int) $timeout, $microseconds);
        fwrite($stream, $packet);
        $responsePacket = fread($stream, 65535);
        fclose($stream);
        if (!is_string($responsePacket) || $responsePacket === '') {
            throw new \RuntimeException('DNS UDP query timed out.');
        }
        $response = DnsWireCodec::parseTlsaResponse($responsePacket, $id, $owner);
        if (!$response->truncated) {
            return $response;
        }
        return DnsWireCodec::parseTlsaResponse(self::tcpQuery($host, $packet, $timeout), $id, $owner);
    }

    private function tcpQuery(string $host, string $packet, float $timeout): string
    {
        $errno  = 0;
        $error  = '';
        $stream = stream_socket_client('tcp://' . $host . ':53', $errno, $error, $timeout, STREAM_CLIENT_CONNECT);
        if (!is_resource($stream)) {
            throw new \RuntimeException('DNS TCP fallback unavailable.');
        }
        $wholeSeconds = (float) (int) $timeout;
        $microseconds = (int) (($timeout - $wholeSeconds) * 1_000_000.0);
        stream_set_timeout($stream, (int) $timeout, $microseconds);
        fwrite($stream, pack('n', strlen($packet)) . $packet);
        $lengthBytes  = $this->readExactly($stream, 2);
        $lengthHeader = unpack('nlength', $lengthBytes);
        if (!is_array($lengthHeader)) {
            fclose($stream);
            throw new \UnexpectedValueException('Invalid DNS TCP response length.');
        }
        $length = $lengthHeader['length'];
        if ($length < 12 || $length > 65535) {
            fclose($stream);
            throw new \UnexpectedValueException('Invalid DNS TCP response length.');
        }
        $response = $this->readExactly($stream, $length);
        fclose($stream);
        return $response;
    }

    /** @param resource $stream */
    private function readExactly($stream, int $length): string
    {
        $data = '';
        while (strlen($data) < $length && !feof($stream)) {
            $remaining = $length - strlen($data);
            if ($remaining < 1) {
                break;
            }
            $part = fread($stream, $remaining);
            if (!is_string($part) || $part === '') {
                break;
            }
            $data .= $part;
        }
        if (strlen($data) !== $length) {
            throw new \RuntimeException('DNS TCP response was truncated.');
        }
        return $data;
    }

    private function rcodeName(int $rcode): string
    {
        return match ($rcode) {
            0 => 'NOERROR', 1 => 'FORMERR', 2 => 'SERVFAIL', 3 => 'NXDOMAIN', 4 => 'NOTIMP', 5 => 'REFUSED', default => 'RCODE_' . $rcode,
        };
    }
}

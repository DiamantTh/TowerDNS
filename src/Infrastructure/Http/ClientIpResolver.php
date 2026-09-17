<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Resolves the real client IP address behind an optional, explicitly
 * configured set of trusted reverse proxies.
 *
 * By default (`$trustedProxies === []`) this trusts nothing beyond
 * `REMOTE_ADDR`, which is the safe behaviour for a single-host deployment:
 * `X-Forwarded-For` is fully attacker-controlled and never consulted.
 *
 * When one or more trusted proxy IPs/CIDRs are configured (operator opt-in
 * via `app.trusted_proxies` in config.local.toml), and the immediate
 * `REMOTE_ADDR` matches one of them, the `X-Forwarded-For` chain is walked
 * from the rightmost (closest) entry backwards, skipping further trusted
 * hops, and the first untrusted address encountered is returned as the real
 * client IP. This is used for both rate-limiting keys and audit logging so
 * that a deployment behind e.g. nginx/traefik does not bucket every visitor
 * under the proxy's own address.
 */
final readonly class ClientIpResolver
{
    /** @param list<string> $trustedProxies IP addresses or CIDR ranges. */
    public function __construct(private array $trustedProxies = []) {}

    public function resolve(ServerRequestInterface $request): ?string
    {
        $params     = $request->getServerParams();
        $remoteAddr = $params['REMOTE_ADDR'] ?? null;
        $remoteAddr = is_string($remoteAddr) && $remoteAddr !== '' ? $remoteAddr : null;

        if ($remoteAddr === null || $this->trustedProxies === [] || !$this->isTrusted($remoteAddr)) {
            return $remoteAddr;
        }

        $forwardedFor = $request->getHeaderLine('X-Forwarded-For');
        if ($forwardedFor === '') {
            return $remoteAddr;
        }

        $hops = array_reverse(array_map(trim(...), explode(',', $forwardedFor)));
        foreach ($hops as $hop) {
            $ip = $this->stripPort($hop);
            if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
                continue;
            }
            if (!$this->isTrusted($ip)) {
                return $ip;
            }
        }

        // Every hop (including the origin) was a trusted proxy — fall back
        // to REMOTE_ADDR rather than guessing.
        return $remoteAddr;
    }

    private function isTrusted(string $ip): bool
    {
        return array_any($this->trustedProxies, fn(string $proxy): bool => $this->ipMatches($ip, $proxy));
    }

    private function ipMatches(string $ip, string $cidrOrIp): bool
    {
        if (!str_contains($cidrOrIp, '/')) {
            return $ip === $cidrOrIp;
        }

        [$subnet, $maskBits] = explode('/', $cidrOrIp, 2);
        $ipBin               = @inet_pton($ip);
        $subnetBin           = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $maskBits      = (int) $maskBits;
        $bytes         = intdiv($maskBits, 8);
        $remainderBits = $maskBits % 8;

        if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
            return false;
        }

        if ($remainderBits > 0) {
            $mask = ~(0xFF >> $remainderBits) & 0xFF;
            if ((ord($ipBin[$bytes]) & $mask) !== (ord($subnetBin[$bytes]) & $mask)) {
                return false;
            }
        }

        return true;
    }

    private function stripPort(string $hostPort): string
    {
        $hostPort = trim($hostPort, '"');

        if (str_starts_with($hostPort, '[')) {
            $end = strpos($hostPort, ']');
            return $end === false ? $hostPort : substr($hostPort, 1, $end - 1);
        }

        // A bare IPv6 address contains multiple colons; only an IPv4
        // "host:port" pair has exactly one.
        if (substr_count($hostPort, ':') === 1) {
            return explode(':', $hostPort, 2)[0];
        }

        return $hostPort;
    }
}

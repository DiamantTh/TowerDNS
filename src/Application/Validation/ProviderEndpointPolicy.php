<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Validation;

/**
 * Guards freely configurable provider endpoints (e.g. a self-hosted PowerDNS
 * API URL) against SSRF against loopback, link-local, and private networks.
 *
 * The secure default rejects plaintext HTTP and any endpoint that resolves
 * into a reserved/private range. Operators who intentionally run a provider
 * on their own LAN must opt in explicitly per credential set via the
 * `allow_private_network` field; there is no blanket bypass.
 */
final class ProviderEndpointPolicy
{
    /**
     * @param array<string, mixed> $credentials Decoded credential map as produced by
     *        {@see \TowerDNS\Application\Contracts\ProviderCredentialSchemaInterface::credentialsFromInput()}.
     * @throws \InvalidArgumentException if the configured base_url is unsafe.
     */
    public static function assertCredentialsSafe(array $credentials): void
    {
        $url = $credentials['base_url'] ?? null;
        if (!is_string($url) || $url === '') {
            return;
        }

        $allowPrivateNetwork = (string) ($credentials['allow_private_network'] ?? '') === '1';

        self::assertAllowed($url, $allowPrivateNetwork);
    }

    public static function assertAllowed(string $url, bool $allowPrivateNetwork): void
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            throw new \InvalidArgumentException('Provider endpoint URL is malformed.');
        }

        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Provider endpoint must use http or https.');
        }

        if ($scheme === 'http' && !$allowPrivateNetwork) {
            throw new \InvalidArgumentException('Plaintext HTTP endpoints require an explicit private-network opt-in.');
        }

        if ($allowPrivateNetwork) {
            // Explicit operator opt-in for self-hosted/LAN deployments.
            return;
        }

        $ips = self::resolveIps($parts['host']);
        if ($ips === []) {
            throw new \InvalidArgumentException('Provider endpoint host could not be resolved.');
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new \InvalidArgumentException('Provider endpoint resolves to a private or reserved network.');
            }
        }
    }

    /** @return list<string> */
    private static function resolveIps(string $host): array
    {
        $host = trim($host, '[]');

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if ($records === false) {
            return [];
        }

        $ips = [];
        foreach ($records as $record) {
            if (isset($record['ip']) && is_string($record['ip'])) {
                $ips[] = $record['ip'];
            }
            if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return $ips;
    }
}

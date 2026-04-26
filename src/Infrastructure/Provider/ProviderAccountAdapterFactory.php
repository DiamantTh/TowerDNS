<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Provider;

use TowerDNS\Application\Contracts\AccountProviderFactoryInterface;
use TowerDNS\Application\Contracts\DnsProviderInterface;
use TowerDNS\Application\Exception\ProviderNotFoundException;
use TowerDNS\Application\Services\CredentialService;
use TowerDNS\Domain\Account\ProviderAccount;
use TowerDNS\Infrastructure\Provider\Cloudflare\CloudflareProvider;
use TowerDNS\Infrastructure\Provider\DeSEC\DeSECApiClient;
use TowerDNS\Infrastructure\Provider\DeSEC\DeSECProvider;
use TowerDNS\Infrastructure\Provider\Inwx\InwxProvider;
use TowerDNS\Infrastructure\Provider\PowerDNS\PowerDnsProvider;

/**
 * Builds a {@see DnsProviderInterface} instance from a {@see ProviderAccount}.
 *
 * Credentials are stored as encrypted JSON blobs. The format per provider type:
 *   desec:      {"token":"<api-token>"}
 *   cloudflare: {"api_token":"<token>"}
 *   inwx:       {"username":"<user>","password":"<pass>"}
 *   powerdns:   {"base_url":"<url>","api_key":"<key>","server_id":"<id>"}
 *
 * PowerDNS is intentionally available here so self-hosted users can attach it
 * to an account, but it MUST NOT be offered to external/untrusted users.
 */
final readonly class ProviderAccountAdapterFactory implements AccountProviderFactoryInterface
{
    public function __construct(private CredentialService $credentialService) {}

    public function buildProvider(ProviderAccount $account): DnsProviderInterface
    {
        $json = $this->credentialService->decrypt($account->credentialsEncrypted);

        /** @var array<string, string> $creds */
        $creds = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);

        $provider = $this->build($account->providerType, $creds);

        // Wipe plaintext JSON from memory
        $this->credentialService->wipe($json);

        return $provider;
    }

    /**
     * @param array<string, string> $creds
     */
    private function build(string $type, array $creds): DnsProviderInterface
    {
        return match ($type) {
            DeSECProvider::ID => new DeSECProvider(
                new DeSECApiClient((string) ($creds['token'] ?? ''))
            ),
            CloudflareProvider::ID => new CloudflareProvider(
                (string) ($creds['api_token'] ?? '')
            ),
            InwxProvider::ID => new InwxProvider(
                (string) ($creds['username'] ?? ''),
                (string) ($creds['password'] ?? ''),
            ),
            PowerDnsProvider::ID => new PowerDnsProvider(
                (string) ($creds['base_url'] ?? ''),
                (string) ($creds['api_key'] ?? ''),
                (string) ($creds['server_id'] ?? 'localhost'),
            ),
            default => throw ProviderNotFoundException::forId($type),
        };
    }
}

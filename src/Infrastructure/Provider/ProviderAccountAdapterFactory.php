<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Provider;

use TowerDNS\Application\Contracts\AccountProviderFactoryInterface;
use TowerDNS\Application\Contracts\DNSProviderInterface;
use TowerDNS\Application\Services\CredentialService;
use TowerDNS\Domain\Account\ProviderAccount;

/**
 * Builds a {@see DNSProviderInterface} instance from a {@see ProviderAccount}.
 *
 * Credentials are stored as encrypted JSON blobs. The format per provider type:
 *   desec:      {"token":"<api-token>"}
 *   cloudflare: {"api_token":"<token>"}
 *   inwx:       {"username":"<user>","password":"<pass>"}
 *   powerdns:   {"base_url":"<url>","api_key":"<key>","server_id":"<id>"}
 *   netcup:     {"customer_number":"<id>","api_key":"<key>","api_password":"<password>","zones":"example.org,example.net"}
 *
 * PowerDNS remains buildable for existing installations and system use, but
 * the central provider definition marks it as unavailable for new user-managed
 * provider accounts.
 */
final readonly class ProviderAccountAdapterFactory implements AccountProviderFactoryInterface
{
    public function __construct(
        private CredentialService $credentialService,
        private DNSProviderFactory $providerFactory,
    ) {}

    public function buildProvider(ProviderAccount $account): DNSProviderInterface
    {
        $json = $this->credentialService->decrypt($account->credentialsEncrypted);
        try {
            /** @var array<string, string> $creds */
            $creds = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
            return $this->providerFactory->build($account->providerType, $creds);
        } finally {
            $this->credentialService->wipe($json);
        }
    }
}

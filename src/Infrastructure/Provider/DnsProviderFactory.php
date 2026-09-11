<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Provider;

use TowerDNS\Application\Contracts\DnsProviderInterface;
use TowerDNS\Application\Exception\ProviderNotFoundException;
use TowerDNS\Infrastructure\Provider\Cloudflare\CloudflareProvider;
use TowerDNS\Infrastructure\Provider\DeSEC\DeSECApiClient;
use TowerDNS\Infrastructure\Provider\DeSEC\DeSECProvider;
use TowerDNS\Infrastructure\Provider\INWX\InwxProvider;
use TowerDNS\Infrastructure\Provider\netcup\NetcupApiClient;
use TowerDNS\Infrastructure\Provider\netcup\NetcupProvider;
use TowerDNS\Infrastructure\Provider\PowerDNS\PowerDnsProvider;

/** Central catalogue and construction point for DNS-provider adapters. */
final class DnsProviderFactory
{
    /**
     * @var array<string, array{
     *   label: string,
     *   user_managed: bool,
     *   credentials: array<string, array{input: string, label: string, required: bool, secret: bool, default?: string}>
     * }>
     */
    private const array DEFINITIONS = [
        'desec' => [
            'label'        => 'deSEC',
            'user_managed' => true,
            'credentials'  => [
                'token' => ['input' => 'desec_token', 'label' => 'API-Token', 'required' => true, 'secret' => true],
            ],
        ],
        'cloudflare' => [
            'label'        => 'Cloudflare',
            'user_managed' => true,
            'credentials'  => [
                'api_token' => ['input' => 'cloudflare_api_token', 'label' => 'API-Token', 'required' => true, 'secret' => true],
            ],
        ],
        'inwx' => [
            'label'        => 'INWX',
            'user_managed' => true,
            'credentials'  => [
                'username' => ['input' => 'inwx_username', 'label' => 'Benutzername', 'required' => true, 'secret' => false],
                'password' => ['input' => 'inwx_password', 'label' => 'Passwort', 'required' => true, 'secret' => true],
            ],
        ],
        'netcup' => [
            'label'        => 'Netcup CCP DNS',
            'user_managed' => true,
            'credentials'  => [
                'customer_number' => ['input' => 'netcup_customer_number', 'label' => 'Kundennummer', 'required' => true, 'secret' => false],
                'api_key'         => ['input' => 'netcup_api_key', 'label' => 'Legacy API-Key', 'required' => true, 'secret' => true],
                'api_password'    => ['input' => 'netcup_api_password', 'label' => 'Legacy API-Passwort', 'required' => true, 'secret' => true],
                'zones'           => ['input' => 'netcup_zones', 'label' => 'Zonen (kommagetrennt)', 'required' => true, 'secret' => false],
            ],
        ],
        'powerdns' => [
            'label'        => 'PowerDNS',
            'user_managed' => false,
            'credentials'  => [
                'base_url'  => ['input' => 'powerdns_base_url', 'label' => 'API-Basis-URL', 'required' => true, 'secret' => false],
                'api_key'   => ['input' => 'powerdns_api_key', 'label' => 'API-Key', 'required' => true, 'secret' => true],
                'server_id' => ['input' => 'powerdns_server_id', 'label' => 'Server-ID', 'required' => false, 'secret' => false, 'default' => 'localhost'],
            ],
        ],
    ];

    public function supports(string $type): bool
    {
        return isset(self::DEFINITIONS[$type]);
    }

    /** @return list<string> */
    public function userManagedTypes(): array
    {
        return array_keys(array_filter(
            self::DEFINITIONS,
            static fn(array $definition): bool => $definition['user_managed'],
        ));
    }

    /**
     * @return array<string, array{
     *   label: string,
     *   user_managed: bool,
     *   credentials: array<string, array{input: string, label: string, required: bool, secret: bool, default?: string}>
     * }>
     */
    public function definitions(): array
    {
        return self::DEFINITIONS;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, string>|null
     */
    public function credentialsFromInput(string $type, array $input): ?array
    {
        $definition = self::DEFINITIONS[$type] ?? null;
        if ($definition === null) {
            return null;
        }

        $credentials = [];
        foreach ($definition['credentials'] as $key => $field) {
            $value             = trim((string) ($input[$field['input']] ?? $field['default'] ?? ''));
            $credentials[$key] = $value;
        }
        return $credentials;
    }

    /** @param array<string, mixed> $credentials */
    public function credentialsComplete(string $type, array $credentials): bool
    {
        $definition = self::DEFINITIONS[$type] ?? null;
        if ($definition === null) {
            return false;
        }
        foreach ($definition['credentials'] as $key => $field) {
            if ($field['required'] && trim((string) ($credentials[$key] ?? '')) === '') {
                return false;
            }
        }
        return true;
    }

    /** @param array<string, mixed> $credentials */
    public function build(string $type, array $credentials): DnsProviderInterface
    {
        if (!$this->supports($type)) {
            throw ProviderNotFoundException::forId($type);
        }
        if (!$this->credentialsComplete($type, $credentials)) {
            throw new \InvalidArgumentException(sprintf('Credentials für DNS-Provider "%s" sind unvollständig.', $type));
        }

        return match ($type) {
            DeSECProvider::ID      => new DeSECProvider(new DeSECApiClient((string) $credentials['token'])),
            CloudflareProvider::ID => new CloudflareProvider((string) $credentials['api_token']),
            InwxProvider::ID       => new InwxProvider((string) $credentials['username'], (string) $credentials['password']),
            NetcupProvider::ID     => new NetcupProvider(
                new NetcupApiClient(
                    (string) $credentials['customer_number'],
                    (string) $credentials['api_key'],
                    (string) $credentials['api_password'],
                ),
                $this->parseZones((string) $credentials['zones']),
            ),
            PowerDnsProvider::ID => new PowerDnsProvider(
                (string) $credentials['base_url'],
                (string) $credentials['api_key'],
                (string) ($credentials['server_id'] ?? 'localhost'),
            ),
            default => throw ProviderNotFoundException::forId($type),
        };
    }

    /** @return list<string> */
    private function parseZones(string $zones): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $zones))));
    }
}

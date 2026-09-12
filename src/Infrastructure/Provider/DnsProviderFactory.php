<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Provider;

use TowerDNS\Application\Contracts\DnsProviderInterface;
use TowerDNS\Application\Exception\ProviderNotFoundException;
use TowerDNS\Application\Module\ProviderModuleRegistry;
use TowerDNS\Infrastructure\Provider\netcup\NetcupApiClient;
use TowerDNS\Infrastructure\Provider\netcup\NetcupProvider;

/** Central catalogue and construction point for DNS-provider adapters. */
final class DnsProviderFactory
{
    public function __construct(private readonly ?ProviderModuleRegistry $moduleRegistry = null) {}
    /**
     * @var array<string, array{
     *   label: string,
     *   user_managed: bool,
     *   credentials: array<string, array{input: string, label: string, required: bool, secret: bool, default?: string}>
     * }>
     */
    private const array DEFINITIONS = [
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
    ];

    public function supports(string $type): bool
    {
        return isset($this->definitions()[$type]);
    }

    /** @return list<string> */
    public function userManagedTypes(): array
    {
        return array_keys(array_filter(
            $this->definitions(),
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
        $definitions = self::DEFINITIONS;
        foreach ($this->moduleRegistry?->definitions() ?? [] as $id => $definition) {
            $definitions[$id] = [
                'label'        => $definition->displayName,
                'user_managed' => $definition->userManaged,
                'credentials'  => $definition->credentials,
            ];
        }
        return $definitions;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, string>|null
     */
    public function credentialsFromInput(string $type, array $input): ?array
    {
        $definition = $this->definitions()[$type] ?? null;
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
        $definition = $this->definitions()[$type] ?? null;
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

        if ($this->moduleRegistry?->has($type)) {
            return $this->moduleRegistry->get($type)->buildProvider($credentials);
        }

        return match ($type) {
            NetcupProvider::ID => new NetcupProvider(
                new NetcupApiClient(
                    (string) $credentials['customer_number'],
                    (string) $credentials['api_key'],
                    (string) $credentials['api_password'],
                ),
                $this->parseZones((string) $credentials['zones']),
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

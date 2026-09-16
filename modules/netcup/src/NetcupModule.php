<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Module\netcup;

use TowerDNS\Application\Contracts\DNSProviderInterface;
use TowerDNS\Application\Module\ModuleManifest;
use TowerDNS\Application\Module\ModuleType;
use TowerDNS\Application\Module\ProviderDefinition;
use TowerDNS\Application\Module\ProviderModuleInterface;

final readonly class NetcupModule implements ProviderModuleInterface
{
    public function manifest(): ModuleManifest
    {
        return new ModuleManifest('towerdns.netcup', 'netcup', '1.0.0', ModuleType::PROVIDER);
    }

    public function providerDefinition(): ProviderDefinition
    {
        return new ProviderDefinition(NetcupProvider::ID, 'netcup', true, [
            'customer_number' => ['input' => 'netcup_customer_number', 'label' => 'module.netcup.credentials.customer-number.label', 'required' => true, 'secret' => false],
            'api_key'         => ['input' => 'netcup_api_key', 'label' => 'module.netcup.credentials.api-key.label', 'required' => true, 'secret' => true],
            'api_password'    => ['input' => 'netcup_api_password', 'label' => 'module.netcup.credentials.api-password.label', 'required' => true, 'secret' => true],
            'zones'           => ['input' => 'netcup_zones', 'label' => 'module.netcup.credentials.zones.label', 'required' => true, 'secret' => false],
        ]);
    }

    public function buildProvider(array $credentials): DNSProviderInterface
    {
        return new NetcupProvider(
            new NetcupAPIClient(
                (string) $credentials['customer_number'],
                (string) $credentials['api_key'],
                (string) $credentials['api_password'],
            ),
            array_values(array_filter(array_map('trim', explode(',', (string) $credentials['zones'])))),
        );
    }
}

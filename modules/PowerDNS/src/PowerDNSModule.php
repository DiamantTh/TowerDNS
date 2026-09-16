<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Module\PowerDNS;

use TowerDNS\Application\Contracts\DnsProviderInterface;
use TowerDNS\Application\Module\ModuleManifest;
use TowerDNS\Application\Module\ModuleType;
use TowerDNS\Application\Module\ProviderDefinition;
use TowerDNS\Application\Module\ProviderModuleInterface;

final readonly class PowerDNSModule implements ProviderModuleInterface
{
    public function manifest(): ModuleManifest
    {
        return new ModuleManifest('towerdns.powerdns', 'PowerDNS', '1.0.0', ModuleType::PROVIDER);
    }

    public function providerDefinition(): ProviderDefinition
    {
        return new ProviderDefinition('powerdns', 'PowerDNS', false, [
            'base_url'  => ['input' => 'powerdns_base_url', 'label' => 'module.powerdns.credentials.base-url.label', 'required' => true, 'secret' => false],
            'api_key'   => ['input' => 'powerdns_api_key', 'label' => 'module.powerdns.credentials.api-key.label', 'required' => true, 'secret' => true],
            'server_id' => ['input' => 'powerdns_server_id', 'label' => 'module.powerdns.credentials.server-id.label', 'required' => false, 'secret' => false, 'default' => 'localhost'],
        ]);
    }

    public function buildProvider(array $credentials): DnsProviderInterface
    {
        return new PowerDNSProvider(
            (string) $credentials['base_url'],
            (string) $credentials['api_key'],
            (string) ($credentials['server_id'] ?? 'localhost'),
        );
    }
}

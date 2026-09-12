<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Module\OVHcloud;

use Ovh\Api;
use TowerDNS\Application\Contracts\DnsProviderInterface;
use TowerDNS\Application\Module\ModuleManifest;
use TowerDNS\Application\Module\ModuleType;
use TowerDNS\Application\Module\ProviderDefinition;
use TowerDNS\Application\Module\ProviderModuleInterface;

final readonly class OVHcloudModule implements ProviderModuleInterface
{
    public function manifest(): ModuleManifest
    {
        return new ModuleManifest('towerdns.ovhcloud', 'OVHcloud', '1.0.0', ModuleType::PROVIDER);
    }

    public function providerDefinition(): ProviderDefinition
    {
        return new ProviderDefinition(OVHcloudProvider::ID, 'OVHcloud', true, [
            'application_key'    => ['input' => 'ovh_application_key', 'label' => 'Application key', 'required' => true, 'secret' => true],
            'application_secret' => ['input' => 'ovh_application_secret', 'label' => 'Application secret', 'required' => true, 'secret' => true],
            'consumer_key'       => ['input' => 'ovh_consumer_key', 'label' => 'Consumer key', 'required' => true, 'secret' => true],
            'endpoint'           => ['input' => 'ovh_endpoint', 'label' => 'API endpoint', 'required' => false, 'secret' => false, 'default' => 'ovh-eu'],
        ]);
    }

    public function buildProvider(array $credentials): DnsProviderInterface
    {
        return new OVHcloudProvider(new Api(
            (string) $credentials['application_key'],
            (string) $credentials['application_secret'],
            (string) ($credentials['endpoint'] ?? 'ovh-eu'),
            (string) $credentials['consumer_key'],
        ));
    }
}

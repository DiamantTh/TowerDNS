<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Module\OVHcloud;

use GuzzleHttp\Client;
use Ovh\Api;
use TowerDNS\Application\Contracts\DNSProviderInterface;
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
            'application_key'    => ['input' => 'ovh_application_key', 'label' => 'module.ovhcloud.credentials.application-key.label', 'required' => true, 'secret' => true],
            'application_secret' => ['input' => 'ovh_application_secret', 'label' => 'module.ovhcloud.credentials.application-secret.label', 'required' => true, 'secret' => true],
            'consumer_key'       => ['input' => 'ovh_consumer_key', 'label' => 'module.ovhcloud.credentials.consumer-key.label', 'required' => true, 'secret' => true],
            'endpoint'           => ['input' => 'ovh_endpoint', 'label' => 'module.ovhcloud.credentials.endpoint.label', 'required' => false, 'secret' => false, 'default' => 'ovh-eu'],
        ]);
    }

    public function buildProvider(array $credentials): DNSProviderInterface
    {
        return new OVHcloudProvider(new Api(
            (string) $credentials['application_key'],
            (string) $credentials['application_secret'],
            (string) ($credentials['endpoint'] ?? 'ovh-eu'),
            (string) $credentials['consumer_key'],
            new Client([
                'timeout'         => 30,
                'connect_timeout' => 5,
                'allow_redirects' => false,
            ]),
        ));
    }
}

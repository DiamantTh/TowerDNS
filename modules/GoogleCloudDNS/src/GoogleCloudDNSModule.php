<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Module\GoogleCloudDNS;

use Google\Client as GoogleClient;
use Google\Service\Dns;
use TowerDNS\Application\Contracts\DnsProviderInterface;
use TowerDNS\Application\Module\ModuleManifest;
use TowerDNS\Application\Module\ModuleType;
use TowerDNS\Application\Module\ProviderDefinition;
use TowerDNS\Application\Module\ProviderModuleInterface;

final readonly class GoogleCloudDNSModule implements ProviderModuleInterface
{
    public function manifest(): ModuleManifest
    {
        return new ModuleManifest('towerdns.googleclouddns', 'Google Cloud DNS', '1.0.0', ModuleType::PROVIDER);
    }

    public function providerDefinition(): ProviderDefinition
    {
        return new ProviderDefinition(GoogleCloudDNSProvider::ID, 'Google Cloud DNS', true, [
            'project_id'           => ['input' => 'google_cloud_dns_project_id', 'label' => 'module.googleclouddns.credentials.project-id.label', 'required' => true, 'secret' => false],
            'service_account_json' => ['input' => 'google_cloud_dns_service_account_json', 'label' => 'module.googleclouddns.credentials.service-account-json.label', 'required' => true, 'secret' => true],
        ]);
    }

    public function buildProvider(array $credentials): DnsProviderInterface
    {
        try {
            $serviceAccount = json_decode((string) $credentials['service_account_json'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('Google Cloud DNS requires valid service-account JSON.', previous: $exception);
        }
        if (!is_array($serviceAccount)) {
            throw new \InvalidArgumentException('Google Cloud DNS requires a JSON object for the service account.');
        }

        $client = new GoogleClient();
        $client->setAuthConfig($serviceAccount);
        $client->setScopes(['https://www.googleapis.com/auth/ndev.clouddns.readwrite']);

        return new GoogleCloudDNSProvider(new Dns($client), (string) $credentials['project_id']);
    }
}

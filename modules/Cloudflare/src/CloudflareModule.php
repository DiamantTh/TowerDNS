<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Module\Cloudflare;

use TowerDNS\Application\Contracts\DnsProviderInterface;
use TowerDNS\Application\Module\ModuleManifest;
use TowerDNS\Application\Module\ModuleType;
use TowerDNS\Application\Module\ProviderDefinition;
use TowerDNS\Application\Module\ProviderModuleInterface;

final readonly class CloudflareModule implements ProviderModuleInterface
{
    public function manifest(): ModuleManifest
    {
        return new ModuleManifest('towerdns.cloudflare', 'Cloudflare', '1.0.0', ModuleType::PROVIDER);
    }

    public function providerDefinition(): ProviderDefinition
    {
        return new ProviderDefinition('cloudflare', 'Cloudflare', true, [
            'api_token' => ['input' => 'cloudflare_api_token', 'label' => 'module.cloudflare.credentials.api-token.label', 'required' => true, 'secret' => true],
        ]);
    }

    public function buildProvider(array $credentials): DnsProviderInterface
    {
        return new CloudflareProvider((string) $credentials['api_token']);
    }
}

<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Module\ClouDNS;

use TowerDNS\Application\Contracts\DnsProviderInterface;
use TowerDNS\Application\Module\ModuleManifest;
use TowerDNS\Application\Module\ModuleType;
use TowerDNS\Application\Module\ProviderDefinition;
use TowerDNS\Application\Module\ProviderModuleInterface;

final readonly class ClouDNSModule implements ProviderModuleInterface
{
    public function manifest(): ModuleManifest
    {
        return new ModuleManifest('towerdns.cloudns', 'ClouDNS', '1.0.0', ModuleType::PROVIDER);
    }

    public function providerDefinition(): ProviderDefinition
    {
        return new ProviderDefinition(ClouDNSProvider::ID, 'ClouDNS', true, [
            'auth_id'       => ['input' => 'cloudns_auth_id', 'label' => 'module.cloudns.credentials.auth-id.label', 'required' => true, 'secret' => false],
            'auth_password' => ['input' => 'cloudns_auth_password', 'label' => 'module.cloudns.credentials.auth-password.label', 'required' => true, 'secret' => true],
            'auth_type'     => ['input' => 'cloudns_auth_type', 'label' => 'module.cloudns.credentials.auth-type.label', 'required' => false, 'secret' => false, 'default' => 'auth-id'],
        ]);
    }

    public function buildProvider(array $credentials): DnsProviderInterface
    {
        return new ClouDNSProvider(new ClouDNSAPIClient(
            (string) $credentials['auth_id'],
            (string) $credentials['auth_password'],
            (string) ($credentials['auth_type'] ?? 'auth-id'),
        ));
    }
}

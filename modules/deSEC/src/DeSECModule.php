<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Module\DeSEC;

use TowerDNS\Application\Contracts\DnsProviderInterface;
use TowerDNS\Application\Module\ModuleManifest;
use TowerDNS\Application\Module\ModuleType;
use TowerDNS\Application\Module\ProviderDefinition;
use TowerDNS\Application\Module\ProviderModuleInterface;

final readonly class DeSECModule implements ProviderModuleInterface
{
    public function manifest(): ModuleManifest
    {
        return new ModuleManifest('towerdns.desec', 'deSEC', '1.0.0', ModuleType::PROVIDER);
    }

    public function providerDefinition(): ProviderDefinition
    {
        return new ProviderDefinition('desec', 'deSEC', true, [
            'token' => ['input' => 'desec_token', 'label' => 'module.desec.credentials.token.label', 'required' => true, 'secret' => true],
        ]);
    }

    public function buildProvider(array $credentials): DnsProviderInterface
    {
        return new DeSECProvider(new DeSECApiClient((string) $credentials['token']));
    }
}

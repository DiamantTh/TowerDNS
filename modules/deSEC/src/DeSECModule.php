<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Module\DeSEC;

use TowerDNS\Application\Contracts\DNSProviderInterface;
use TowerDNS\Application\Module\ModuleManifest;
use TowerDNS\Application\Module\ModuleType;
use TowerDNS\Application\Module\ProviderDefinition;
use TowerDNS\Application\Module\ProviderModuleInterface;

final readonly class DeSECModule implements ProviderModuleInterface
{
    #[\Override]
    public function manifest(): ModuleManifest
    {
        return new ModuleManifest('towerdns.desec', 'deSEC', '1.0.0', ModuleType::PROVIDER);
    }

    #[\Override]
    public function providerDefinition(): ProviderDefinition
    {
        return new ProviderDefinition('desec', 'deSEC', true, [
            'token' => ['input' => 'desec_token', 'label' => 'module.desec.credentials.token.label', 'required' => true, 'secret' => true],
        ]);
    }

    #[\Override]
    public function buildProvider(array $credentials): DNSProviderInterface
    {
        return new DeSECProvider(new DeSECApiClient((string) $credentials['token']));
    }
}

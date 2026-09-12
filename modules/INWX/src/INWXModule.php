<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Module\INWX;

use TowerDNS\Application\Contracts\DnsProviderInterface;
use TowerDNS\Application\Module\ModuleManifest;
use TowerDNS\Application\Module\ModuleType;
use TowerDNS\Application\Module\ProviderDefinition;
use TowerDNS\Application\Module\ProviderModuleInterface;

final readonly class INWXModule implements ProviderModuleInterface
{
    public function manifest(): ModuleManifest
    {
        return new ModuleManifest('towerdns.inwx', 'INWX', '1.0.0', ModuleType::PROVIDER);
    }

    public function providerDefinition(): ProviderDefinition
    {
        return new ProviderDefinition(INWXProvider::ID, 'INWX', true, [
            'username' => ['input' => 'inwx_username', 'label' => 'Benutzername', 'required' => true, 'secret' => false],
            'password' => ['input' => 'inwx_password', 'label' => 'Passwort', 'required' => true, 'secret' => true],
        ]);
    }

    public function buildProvider(array $credentials): DnsProviderInterface
    {
        return new INWXProvider(
            (string) $credentials['username'],
            (string) $credentials['password'],
        );
    }
}

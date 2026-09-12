<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Provider;

use PHPUnit\Framework\TestCase;
use TowerDNS\Application\Exception\ProviderNotFoundException;
use TowerDNS\Application\Module\LocalModuleDiscovery;
use TowerDNS\Application\Module\ProviderModuleRegistry;
use TowerDNS\Infrastructure\Provider\DnsProviderFactory;
use TowerDNS\Infrastructure\Provider\PowerDNS\PowerDnsProvider;
use TowerDNS\Module\DeSEC\DeSECProvider;

final class DnsProviderFactoryTest extends TestCase
{
    public function testPowerDnsIsSystemOnlyButStillBuildable(): void
    {
        $factory = new DnsProviderFactory();

        self::assertNotContains('powerdns', $factory->userManagedTypes());
        self::assertInstanceOf(PowerDnsProvider::class, $factory->build('powerdns', [
            'base_url' => 'https://pdns.example.test',
            'api_key'  => 'secret',
        ]));
    }

    public function testCredentialSchemaSuppliesOptionalDefaults(): void
    {
        $factory     = new DnsProviderFactory();
        $credentials = $factory->credentialsFromInput('powerdns', [
            'powerdns_base_url' => 'https://pdns.example.test',
            'powerdns_api_key'  => 'secret',
        ]);

        self::assertSame('localhost', $credentials['server_id'] ?? null);
        self::assertTrue($factory->credentialsComplete('powerdns', $credentials ?? []));
    }

    public function testUnknownProviderUsesDomainSpecificException(): void
    {
        $this->expectException(ProviderNotFoundException::class);
        new DnsProviderFactory()->build('unknown', []);
    }

    public function testBuildsDeSecFromItsLocalModuleContribution(): void
    {
        $discovery = new LocalModuleDiscovery(dirname(__DIR__, 3) . '/modules');
        $factory   = new DnsProviderFactory(new ProviderModuleRegistry($discovery->providerModules()));

        self::assertInstanceOf(DeSECProvider::class, $factory->build('desec', ['token' => 'secret']));
        self::assertSame('deSEC', $factory->definitions()['desec']['label']);
    }
}

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
use TowerDNS\Module\Cloudflare\CloudflareProvider;
use TowerDNS\Module\DeSEC\DeSECProvider;
use TowerDNS\Module\INWX\INWXProvider;
use TowerDNS\Module\PowerDNS\PowerDNSProvider;

final class DnsProviderFactoryTest extends TestCase
{
    public function testPowerDNSIsSystemOnlyButStillBuildable(): void
    {
        $factory = $this->moduleFactory();

        self::assertNotContains('powerdns', $factory->userManagedTypes());
        self::assertInstanceOf(PowerDNSProvider::class, $factory->build('powerdns', [
            'base_url' => 'https://pdns.example.test',
            'api_key'  => 'secret',
        ]));
    }

    public function testCredentialSchemaSuppliesOptionalDefaults(): void
    {
        $factory     = $this->moduleFactory();
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
        $factory = $this->moduleFactory();

        self::assertInstanceOf(DeSECProvider::class, $factory->build('desec', ['token' => 'secret']));
        self::assertSame('deSEC', $factory->definitions()['desec']['label']);
    }

    public function testBuildsCloudflareFromItsLocalModuleContribution(): void
    {
        $factory = $this->moduleFactory();

        self::assertInstanceOf(CloudflareProvider::class, $factory->build('cloudflare', ['api_token' => 'secret']));
        self::assertSame('Cloudflare', $factory->definitions()['cloudflare']['label']);
    }

    public function testBuildsINWXFromItsLocalModuleContribution(): void
    {
        $factory = $this->moduleFactory();

        self::assertInstanceOf(INWXProvider::class, $factory->build('inwx', [
            'username' => 'user',
            'password' => 'secret',
        ]));
        self::assertSame('INWX', $factory->definitions()['inwx']['label']);
    }

    private function moduleFactory(): DnsProviderFactory
    {
        $discovery = new LocalModuleDiscovery(dirname(__DIR__, 3) . '/modules');
        return new DnsProviderFactory(new ProviderModuleRegistry($discovery->providerModules()));
    }
}

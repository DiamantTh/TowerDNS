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
use TowerDNS\Module\ClouDNS\ClouDNSProvider;
use TowerDNS\Module\DeSEC\DeSECProvider;
use TowerDNS\Module\GoogleCloudDNS\GoogleCloudDNSProvider;
use TowerDNS\Module\INWX\INWXProvider;
use TowerDNS\Module\netcup\NetcupProvider;
use TowerDNS\Module\OVHcloud\OVHcloudProvider;
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

    public function testBuildsNetcupFromItsLocalModuleContribution(): void
    {
        $factory = $this->moduleFactory();

        self::assertInstanceOf(NetcupProvider::class, $factory->build('netcup', [
            'customer_number' => '12345',
            'api_key'         => 'key',
            'api_password'    => 'secret',
            'zones'           => 'example.org',
        ]));
        self::assertSame('netcup', $factory->definitions()['netcup']['label']);
    }

    public function testBuildsOVHcloudFromItsLocalModuleContribution(): void
    {
        $factory = $this->moduleFactory();

        self::assertInstanceOf(OVHcloudProvider::class, $factory->build('ovh', [
            'application_key'    => 'app-key',
            'application_secret' => 'app-secret',
            'consumer_key'       => 'consumer-key',
        ]));
        self::assertSame('OVHcloud', $factory->definitions()['ovh']['label']);
    }

    public function testBuildsClouDNSFromItsLocalModuleContribution(): void
    {
        $factory = $this->moduleFactory();

        self::assertInstanceOf(ClouDNSProvider::class, $factory->build('cloudns', [
            'auth_id'       => '123',
            'auth_password' => 'secret',
        ]));
        self::assertSame('ClouDNS', $factory->definitions()['cloudns']['label']);
    }

    public function testBuildsGoogleCloudDNSFromItsLocalModuleContribution(): void
    {
        $factory        = $this->moduleFactory();
        $serviceAccount = json_encode([
            'type'         => 'service_account',
            'project_id'   => 'example-project',
            'private_key'  => '-----BEGIN PRIVATE KEY-----\nexample\n-----END PRIVATE KEY-----\n',
            'client_email' => 'towerdns@example-project.iam.gserviceaccount.com',
            'client_id'    => '1234567890',
        ], JSON_THROW_ON_ERROR);

        self::assertInstanceOf(GoogleCloudDNSProvider::class, $factory->build('google-cloud-dns', [
            'project_id'           => 'example-project',
            'service_account_json' => $serviceAccount,
        ]));
        self::assertSame('Google Cloud DNS', $factory->definitions()['google-cloud-dns']['label']);
    }

    private function moduleFactory(): DnsProviderFactory
    {
        $discovery = new LocalModuleDiscovery(dirname(__DIR__, 3) . '/modules');
        return new DnsProviderFactory(new ProviderModuleRegistry($discovery->providerModules()));
    }
}

<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Application\Module;

use PHPUnit\Framework\TestCase;
use TowerDNS\Application\Contracts\DnsProviderInterface;
use TowerDNS\Application\Module\ModuleManifest;
use TowerDNS\Application\Module\ModuleType;
use TowerDNS\Application\Module\ProviderDefinition;
use TowerDNS\Application\Module\ProviderModuleInterface;
use TowerDNS\Application\Module\ProviderModuleRegistry;

final class ProviderModuleRegistryTest extends TestCase
{
    public function testRegistersProviderDefinitionByTechnicalId(): void
    {
        $registry = new ProviderModuleRegistry([$this->module('desec', 'deSEC')]);

        self::assertTrue($registry->has('desec'));
        self::assertSame('deSEC', $registry->definitions()['desec']->displayName);
    }

    public function testRejectsDuplicateProviderIds(): void
    {
        $this->expectException(\LogicException::class);
        new ProviderModuleRegistry([$this->module('desec', 'deSEC'), $this->module('desec', 'Different')]);
    }

    private function module(string $id, string $displayName): ProviderModuleInterface
    {
        return new readonly class ($id, $displayName) implements ProviderModuleInterface {
            public function __construct(private string $id, private string $displayName) {}
            public function manifest(): ModuleManifest
            {
                return new ModuleManifest('towerdns.' . $this->id, $this->displayName, '1.0.0', ModuleType::PROVIDER);
            }
            public function providerDefinition(): ProviderDefinition
            {
                return new ProviderDefinition($this->id, $this->displayName, true, []);
            }
            public function buildProvider(array $credentials): DnsProviderInterface
            {
                throw new \LogicException('Not needed by this registry test.');
            }
        };
    }
}

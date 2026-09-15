<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Application\Services;

use PHPUnit\Framework\TestCase;
use TowerDNS\Application\Contracts\ProviderCredentialSchemaInterface;
use TowerDNS\Application\Exception\ProviderConfigurationException;
use TowerDNS\Application\Repository\SystemProviderConfigurationStoreInterface;
use TowerDNS\Application\Services\AuthorizationService;
use TowerDNS\Application\Services\RbacPermissionChecker;
use TowerDNS\Application\Services\SystemProviderConfigurationService;
use TowerDNS\Domain\Auth\Permission;
use TowerDNS\Domain\Auth\Role;
use TowerDNS\Domain\Auth\User;

final class SystemProviderConfigurationServiceTest extends TestCase
{
    public function testItPersistsSystemConfigurationAndPreservesBlankSecretInput(): void
    {
        $store   = new InMemorySystemProviderConfigurationStore(['providers' => ['example' => ['token' => 'existing', 'endpoint' => 'https://old.example']]]);
        $service = $this->service($store);

        $service->update($this->operator(), 'example', ['token_input' => '', 'endpoint_input' => 'https://new.example']);

        self::assertSame('existing', $store->configuration['providers']['example']['token']);
        self::assertSame('https://new.example', $store->configuration['providers']['example']['endpoint']);
    }

    public function testItRejectsUnknownProvidersWithStableReason(): void
    {
        $this->expectException(ProviderConfigurationException::class);
        $this->expectExceptionMessage(ProviderConfigurationException::UNKNOWN_PROVIDER);

        $this->service(new InMemorySystemProviderConfigurationStore())->update($this->operator(), 'missing', []);
    }

    public function testItRequiresSystemProviderConfigurationPermission(): void
    {
        $service = $this->service(new InMemorySystemProviderConfigurationStore());
        $user    = new User('user', 'user@example.test');

        $this->expectException(\TowerDNS\Application\Exception\AuthorizationException::class);
        $service->update($user, 'example', ['token_input' => 'token', 'endpoint_input' => 'https://example.test']);
    }

    public function testItRejectsAProviderTypeThatDoesNotAllowSystemConfiguration(): void
    {
        $service = new SystemProviderConfigurationService(
            new AuthorizationService(new RbacPermissionChecker()),
            new InMemorySystemProviderConfigurationStore(),
            new ExampleProviderCredentialSchema(false),
        );

        $this->expectException(ProviderConfigurationException::class);
        $this->expectExceptionMessage(ProviderConfigurationException::UNKNOWN_PROVIDER);
        $service->update($this->operator(), 'example', ['token_input' => 'token', 'endpoint_input' => 'https://example.test']);
    }

    private function service(SystemProviderConfigurationStoreInterface $store): SystemProviderConfigurationService
    {
        return new SystemProviderConfigurationService(
            new AuthorizationService(new RbacPermissionChecker()),
            $store,
            new ExampleProviderCredentialSchema(),
        );
    }

    private function operator(): User
    {
        return new User('operator', 'operator@example.test', [
            new Role('provider-op', 'Provider operator', [Permission::PROVIDER_CONFIG_MANAGE]),
        ]);
    }
}

final class InMemorySystemProviderConfigurationStore implements SystemProviderConfigurationStoreInterface
{
    /** @param array<string, mixed> $configuration */
    public function __construct(public array $configuration = ['providers' => []]) {}

    public function load(): array
    {
        return $this->configuration;
    }

    public function save(array $configuration): void
    {
        $this->configuration = $configuration;
    }

    public function update(callable $mutator): void
    {
        $this->configuration = $mutator($this->configuration);
    }
}

final readonly class ExampleProviderCredentialSchema implements ProviderCredentialSchemaInterface
{
    public function __construct(private bool $systemConfigurable = true) {}

    public function definitions(): array
    {
        return ['example' => [
            'label'               => 'Example',
            'user_managed'        => true,
            'system_configurable' => $this->systemConfigurable,
            'credentials'         => [
                'token'    => ['input' => 'token_input', 'label' => 'Token', 'required' => true, 'secret' => true],
                'endpoint' => ['input' => 'endpoint_input', 'label' => 'Endpoint', 'required' => true, 'secret' => false],
            ],
        ]];
    }

    /** @return array<string, string>|null */
    public function credentialsFromInput(string $type, array $input): ?array
    {
        if ($type !== 'example') {
            return null;
        }
        return ['token' => trim((string) ($input['token_input'] ?? '')), 'endpoint' => trim((string) ($input['endpoint_input'] ?? ''))];
    }

    public function credentialsComplete(string $type, array $credentials): bool
    {
        return $type === 'example' && $credentials['token'] !== '' && $credentials['endpoint'] !== '';
    }
}

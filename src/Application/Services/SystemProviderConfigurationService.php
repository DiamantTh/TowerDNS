<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Services;

use TowerDNS\Application\Contracts\ProviderCredentialSchemaInterface;
use TowerDNS\Application\Exception\ProviderConfigurationException;
use TowerDNS\Application\Repository\SystemProviderConfigurationStoreInterface;
use TowerDNS\Domain\Auth\Permission;
use TowerDNS\Domain\Auth\User;

/**
 * Manages explicitly system-wide provider configuration.
 *
 * This is intentionally separate from tenant ProviderAccount instances. The
 * service has no HTTP, template, redirect, or session dependency and may be
 * reused by a future API or CLI command.
 */
final readonly class SystemProviderConfigurationService
{
    public function __construct(
        private AuthorizationService $authorization,
        private SystemProviderConfigurationStoreInterface $store,
        private ProviderCredentialSchemaInterface $schemas,
    ) {}

    /**
     * @param array<string, mixed> $input
     * @throws ProviderConfigurationException
     */
    public function update(User $actor, string $providerType, array $input): void
    {
        $this->authorization->assert($actor, Permission::PROVIDER_CONFIG_MANAGE);

        $definition = $this->schemas->definitions()[$providerType] ?? null;
        if ($definition === null) {
            throw new ProviderConfigurationException(ProviderConfigurationException::UNKNOWN_PROVIDER);
        }

        $this->store->update(function (array $configuration) use ($providerType, $input, $definition): array {
            $providers   = (array) ($configuration['providers'] ?? []);
            $stored      = (array) ($providers[$providerType] ?? []);
            $credentials = $this->schemas->credentialsFromInput($providerType, $input);

            if ($credentials === null) {
                throw new ProviderConfigurationException(ProviderConfigurationException::UNKNOWN_PROVIDER);
            }

            foreach ($definition['credentials'] as $key => $field) {
                if ($field['secret'] && $credentials[$key] === '' && isset($stored[$key])) {
                    $credentials[$key] = (string) $stored[$key];
                }
            }

            if (!$this->schemas->credentialsComplete($providerType, $credentials)) {
                throw new ProviderConfigurationException(ProviderConfigurationException::INCOMPLETE_CREDENTIALS);
            }

            $providers[$providerType]   = $credentials;
            $configuration['providers'] = $providers;
            return $configuration;
        });
    }

    /** @return array<string, mixed> */
    public function current(User $actor): array
    {
        $this->authorization->assert($actor, Permission::PROVIDER_CONFIG_MANAGE);
        return $this->store->load();
    }

    /**
     * @return array<string, array{
     *   label: string,
     *   user_managed: bool,
     *   credentials: array<string, array{input: string, label: string, required: bool, secret: bool, default?: string}>
     * }>
     */
    public function definitions(): array
    {
        return $this->schemas->definitions();
    }

    /** @param array<string, mixed> $credentials */
    public function credentialsComplete(string $providerType, array $credentials): bool
    {
        return $this->schemas->credentialsComplete($providerType, $credentials);
    }
}

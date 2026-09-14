<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Provider;

use TowerDNS\Application\Contracts\ProviderCredentialSchemaInterface;
use TowerDNS\Application\Module\ProviderModuleRegistry;

/** Exposes local provider-module credential schemas without constructing adapters. */
final readonly class ModuleProviderCredentialSchemaCatalog implements ProviderCredentialSchemaInterface
{
    public function __construct(private ProviderModuleRegistry $modules) {}

    public function definitions(): array
    {
        $definitions = [];
        foreach ($this->modules->definitions() as $id => $definition) {
            $definitions[$id] = [
                'label'        => $definition->displayName,
                'user_managed' => $definition->userManaged,
                'credentials'  => $definition->credentials,
            ];
        }
        return $definitions;
    }

    /** @return array<string, string>|null */
    public function credentialsFromInput(string $type, array $input): ?array
    {
        $definition = $this->definitions()[$type] ?? null;
        if ($definition === null) {
            return null;
        }

        $credentials = [];
        foreach ($definition['credentials'] as $key => $field) {
            $credentials[$key] = trim((string) ($input[$field['input']] ?? $field['default'] ?? ''));
        }
        return $credentials;
    }

    public function credentialsComplete(string $type, array $credentials): bool
    {
        $definition = $this->definitions()[$type] ?? null;
        if ($definition === null) {
            return false;
        }

        foreach ($definition['credentials'] as $key => $field) {
            if ($field['required'] && trim((string) ($credentials[$key] ?? '')) === '') {
                return false;
            }
        }
        return true;
    }
}

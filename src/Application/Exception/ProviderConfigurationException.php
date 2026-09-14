<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Exception;

/** Structured failure suitable for a web UI, CLI, or a future API boundary. */
final class ProviderConfigurationException extends \RuntimeException
{
    public const string UNKNOWN_PROVIDER       = 'unknown_provider';
    public const string INCOMPLETE_CREDENTIALS = 'incomplete_credentials';

    public function __construct(public readonly string $reason)
    {
        parent::__construct('System provider configuration failed: ' . $reason);
    }
}

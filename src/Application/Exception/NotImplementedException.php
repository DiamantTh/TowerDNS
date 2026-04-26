<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Exception;

/**
 * Thrown by provider adapters that ship as scaffolding without a backend
 * implementation yet.
 */
final class NotImplementedException extends \RuntimeException
{
    public static function forFeature(string $providerId, string $feature): self
    {
        return new self(sprintf('Provider "%s" implementiert "%s" noch nicht.', $providerId, $feature));
    }
}

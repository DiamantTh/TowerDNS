<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Exception;

use TowerDNS\Application\DTO\ProviderConnectionVerification;

/** Sanitized provider-level verification failure with a stable TowerDNS category. */
final class ProviderConnectionVerificationException extends ProviderRequestException
{
    public function __construct(public readonly string $category, ?\Throwable $previous = null)
    {
        parent::__construct('Provider connection verification failed.', previous: $previous);
        ProviderConnectionVerification::failure($category);
    }
}

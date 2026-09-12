<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Module\DeSEC;

use TowerDNS\Application\Exception\ProviderRequestException;

final class DeSECApiException extends ProviderRequestException
{
    public function __construct(
        string $message,
        int $code = 0,
        ?\Throwable $previous = null,
        private readonly int $retryAfter = 0,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Seconds to wait before retrying, from a Retry-After response header.
     * Returns 0 when the header was absent.
     */
    public function retryAfter(): int
    {
        return $this->retryAfter;
    }
}

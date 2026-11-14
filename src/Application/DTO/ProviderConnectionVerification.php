<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\DTO;

/** Stable, non-sensitive result shared by HTTP, CLI, and future API callers. */
final readonly class ProviderConnectionVerification
{
    public const string SUCCESS               = 'success';
    public const string AUTHENTICATION_FAILED = 'authentication_failed';
    public const string PERMISSION_DENIED     = 'permission_denied';
    public const string RATE_LIMITED          = 'rate_limited';
    public const string TIMEOUT               = 'timeout';
    public const string ENDPOINT_UNREACHABLE  = 'endpoint_unreachable';
    public const string TLS_ERROR             = 'tls_error';
    public const string PROVIDER_ERROR        = 'provider_error';
    public const string MALFORMED_RESPONSE    = 'malformed_response';

    private const array CATEGORIES = [
        self::SUCCESS,
        self::AUTHENTICATION_FAILED,
        self::PERMISSION_DENIED,
        self::RATE_LIMITED,
        self::TIMEOUT,
        self::ENDPOINT_UNREACHABLE,
        self::TLS_ERROR,
        self::PROVIDER_ERROR,
        self::MALFORMED_RESPONSE,
    ];

    private function __construct(public string $category, public ?int $httpStatus = null)
    {
        if (!in_array($category, self::CATEGORIES, true)) {
            throw new \InvalidArgumentException('Unknown provider verification category.');
        }
    }

    public static function success(): self
    {
        return new self(self::SUCCESS);
    }

    public static function failure(string $category, ?int $httpStatus = null): self
    {
        if ($category === self::SUCCESS) {
            throw new \InvalidArgumentException('A failed verification cannot use the success category.');
        }
        return new self($category, $httpStatus);
    }

    public function succeeded(): bool
    {
        return $this->category === self::SUCCESS;
    }
}

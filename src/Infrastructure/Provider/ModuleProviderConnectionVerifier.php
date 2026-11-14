<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Provider;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\TransferException;
use TowerDNS\Application\Contracts\ConnectionVerifiableProviderInterface;
use TowerDNS\Application\Contracts\ProviderConnectionVerifierInterface;
use TowerDNS\Application\DTO\ProviderConnectionVerification;
use TowerDNS\Application\Exception\ProviderConnectionVerificationException;
use TowerDNS\Application\Exception\ProviderRequestException;
use TowerDNS\Application\Module\ProviderModuleRegistry;
use TowerDNS\Infrastructure\RateLimit\RateLimitExceededException;

/** Runs module-owned read-only verification and maps transport failures to stable categories. */
final readonly class ModuleProviderConnectionVerifier implements ProviderConnectionVerifierInterface
{
    public function __construct(private ProviderModuleRegistry $modules) {}

    #[\Override]
    public function verify(string $providerType, array $credentials): ProviderConnectionVerification
    {
        try {
            $provider = $this->modules->get($providerType)->buildProvider($credentials);
            if (!$provider instanceof ConnectionVerifiableProviderInterface) {
                return ProviderConnectionVerification::failure(ProviderConnectionVerification::PROVIDER_ERROR);
            }
            return $provider->verifyConnection();
        } catch (\Throwable $exception) {
            return ProviderConnectionVerification::failure($this->category($exception), $this->status($exception));
        }
    }

    private function category(\Throwable $exception): string
    {
        if ($exception instanceof ProviderConnectionVerificationException) {
            return $exception->category;
        }
        if ($exception instanceof RateLimitExceededException) {
            return ProviderConnectionVerification::RATE_LIMITED;
        }

        $status = $this->status($exception);
        if ($status === 401) {
            return ProviderConnectionVerification::AUTHENTICATION_FAILED;
        }
        if ($status === 403) {
            return ProviderConnectionVerification::PERMISSION_DENIED;
        }
        if ($status === 429) {
            return ProviderConnectionVerification::RATE_LIMITED;
        }

        for ($cause = $exception; $cause instanceof \Throwable; $cause = $cause->getPrevious()) {
            if ($cause instanceof ConnectException) {
                $errno = (int) ($cause->getHandlerContext()['errno'] ?? 0);
                if ($errno === 28) {
                    return ProviderConnectionVerification::TIMEOUT;
                }
                if (in_array($errno, [35, 51, 58, 60, 77, 80, 82], true)) {
                    return ProviderConnectionVerification::TLS_ERROR;
                }
                return ProviderConnectionVerification::ENDPOINT_UNREACHABLE;
            }
            if ($cause instanceof TransferException) {
                return ProviderConnectionVerification::ENDPOINT_UNREACHABLE;
            }
        }

        if ($exception instanceof ProviderRequestException) {
            return $status >= 500 ? ProviderConnectionVerification::PROVIDER_ERROR : ProviderConnectionVerification::MALFORMED_RESPONSE;
        }

        return $exception instanceof \InvalidArgumentException
            ? ProviderConnectionVerification::MALFORMED_RESPONSE
            : ProviderConnectionVerification::PROVIDER_ERROR;
    }

    private function status(\Throwable $exception): ?int
    {
        for ($cause = $exception; $cause instanceof \Throwable; $cause = $cause->getPrevious()) {
            $code = $cause->getCode();
            if (is_int($code) && $code >= 100 && $code <= 599) {
                return $code;
            }
        }
        return null;
    }
}

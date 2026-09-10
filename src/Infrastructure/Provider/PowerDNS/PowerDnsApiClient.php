<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Provider\PowerDNS;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use TowerDNS\Application\Exception\ProviderRequestException;
use TowerDNS\Infrastructure\RateLimit\RateLimitExceededException;

/** Transport, authentication and server-feature detection for PowerDNS. */
final class PowerDnsApiClient
{
    private readonly ClientInterface $http;
    private ?bool $supportsExtendFlag = null;

    public function __construct(
        string $baseUrl,
        private readonly string $apiKey,
        private readonly string $serverId = 'localhost',
        ?ClientInterface $http = null,
    ) {
        if ($baseUrl === '' || $apiKey === '') {
            throw new ProviderRequestException('PowerDNS-Adapter benoetigt Basis-URL und API-Key.');
        }
        $this->http = $http ?? new Client([
            'base_uri'    => rtrim($baseUrl, '/') . '/',
            'timeout'     => 30,
            'http_errors' => true,
        ]);
    }

    public function serverPath(string $suffix): string
    {
        return sprintf('api/v1/servers/%s/%s', rawurlencode($this->serverId), $suffix);
    }

    public function supportsExtend(): bool
    {
        if ($this->supportsExtendFlag !== null) {
            return $this->supportsExtendFlag;
        }
        try {
            $info     = (array) $this->request('GET', $this->serverPath(''));
            $rawValue = $info['version'] ?? '0.0.0';
            $raw      = is_string($rawValue) ? $rawValue : '0.0.0';
        } catch (\Throwable) {
            return $this->supportsExtendFlag = false;
        }
        $version                         = preg_replace('/[^0-9.].*$/', '', $raw) ?? '0.0.0';
        $parts                           = explode('.', $version . '.0.0');
        [$major, $minor, $patch]         = [(int) $parts[0], (int) $parts[1], (int) $parts[2]];
        return $this->supportsExtendFlag = match (true) {
            $major                                 >= 6                                  => true,
            $major === 5 && $minor                 >= 1                  => true,
            $major === 5 && $minor === 0 && $patch >= 2  => true,
            $major === 4 && $minor === 9 && $patch >= 12 => true,
            default                                      => false,
        };
    }

    /** @param array<string, mixed>|null $data */
    public function request(string $method, string $endpoint, ?array $data = null, bool $decode = true): mixed
    {
        try {
            $options = [RequestOptions::HEADERS => [
                'X-API-Key' => $this->apiKey,
                'Accept'    => 'application/json',
            ]];
            if ($data !== null) {
                $options[RequestOptions::JSON] = $data;
            }
            $response = $this->http->request($method, $endpoint, $options);
        } catch (BadResponseException $e) {
            $status = $e->getResponse()->getStatusCode();
            if ($status === 429) {
                $retryAfter = (int) ($e->getResponse()->getHeaderLine('Retry-After') ?: 5);
                throw new RateLimitExceededException(PowerDnsProvider::ID, $retryAfter, $e);
            }
            $detail = '';
            try {
                $raw     = $e->getResponse()->getBody()->getContents();
                $decoded = json_decode($raw, true);
                $detail  = is_array($decoded) && isset($decoded['error']) ? (string) $decoded['error'] : $raw;
            } catch (\Throwable) {
            }
            throw new ProviderRequestException(
                sprintf('PowerDNS API HTTP %d: %s', $status, $detail ?: $e->getMessage()),
                $status,
                $e,
            );
        } catch (GuzzleException $e) {
            throw new ProviderRequestException('PowerDNS API-Aufruf fehlgeschlagen: ' . $e->getMessage(), (int) $e->getCode(), $e);
        }

        if (!$decode) {
            return null;
        }
        $body = $response->getBody()->getContents();
        return $body === '' ? [] : json_decode($body, true);
    }
}

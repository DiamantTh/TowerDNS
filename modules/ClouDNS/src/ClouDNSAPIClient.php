<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Module\ClouDNS;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use TowerDNS\Application\Exception\ProviderRequestException;

/** DNS hosting API only; credentials always travel in the POST body. */
final class ClouDNSAPIClient
{
    private readonly ClientInterface $http;

    public function __construct(
        private readonly string $authId,
        private readonly string $authPassword,
        private readonly string $authType = 'auth-id',
        ?ClientInterface $http = null,
    ) {
        if ($authId === '' || $authPassword === '' || !in_array($authType, ['auth-id', 'sub-auth-id', 'sub-auth-user'], true)) {
            throw new ProviderRequestException('ClouDNS requires an authentication ID, password and valid authentication type.');
        }
        $this->http = $http ?? new Client();
    }

    /** @param array<string, scalar> $parameters
     *  @return array<array-key, mixed>
     */
    public function request(string $operation, array $parameters = []): array
    {
        try {
            $response = $this->http->request('POST', 'https://api.cloudns.net/dns/' . $operation . '.json', [
                'form_params'     => [$this->authType => $this->authId, 'auth-password' => $this->authPassword] + $parameters,
                'timeout'         => 30,
                'http_errors'     => false,
                'allow_redirects' => false,
                'verify'          => true,
            ]);
        } catch (GuzzleException) {
            // Transport exceptions can contain the request body and credentials.
            throw new ProviderRequestException('ClouDNS transport request failed.');
        }
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new ProviderRequestException('ClouDNS HTTP request failed.', $response->getStatusCode());
        }
        try {
            $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ProviderRequestException('ClouDNS returned invalid JSON.');
        }
        if (!is_array($data)) {
            throw new ProviderRequestException('ClouDNS returned an invalid response.');
        }
        if (isset($data['status']) && is_string($data['status']) && !in_array($data['status'], ['Success', '1', '0'], true)) {
            throw new ProviderRequestException('ClouDNS rejected the DNS request.');
        }
        return $data;
    }

    /** @param array<string, scalar> $parameters
     *  @return list<array<string, mixed>>
     */
    public function listAll(string $operation, array $parameters = []): array
    {
        $rows = [];
        $seen = [];
        for ($page = 1; ; $page++) {
            $batch = $this->request($operation, $parameters + ['page' => $page, 'rows-per-page' => 100]);
            foreach ($batch as $key => $row) {
                if (!is_array($row)) {
                    throw new ProviderRequestException('ClouDNS returned an invalid list.');
                }
                if ($operation === 'records') {
                    $row['id'] ??= (string) $key;
                }
                $fingerprint = serialize($row);
                if (isset($seen[$fingerprint])) {
                    throw new ProviderRequestException('ClouDNS pagination repeated a result.');
                }
                $seen[$fingerprint] = true;
                $rows[]             = $row;
            }
            if (count($batch) < 100) {
                return $rows;
            }
        }
    }
}

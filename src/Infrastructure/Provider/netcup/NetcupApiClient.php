<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Provider\netcup;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;

/**
 * Client for Netcup's legacy CCP DNS JSON API.
 *
 * The API deliberately has no per-record mutation endpoint: updateDnsRecords
 * replaces the complete record list. The provider adapter therefore always
 * reads the list first and sends all unrelated records back unchanged.
 *
 * @see https://www.netcup.com/en/helpcenter/documentation/domain/our-api
 */
final readonly class NetcupApiClient
{
    public const string DEFAULT_ENDPOINT = 'https://ccp.netcup.net/run/webservice/servers/endpoint.php?JSON';

    private ClientInterface $http;

    public function __construct(
        private string $customerNumber,
        private string $apiKey,
        private string $apiPassword,
        ?ClientInterface $http = null,
        string $endpoint = self::DEFAULT_ENDPOINT,
    ) {
        if ($customerNumber === '' || $apiKey === '' || $apiPassword === '') {
            throw new NetcupApiException('Netcup-Kundennummer, API-Key und API-Passwort dürfen nicht leer sein.');
        }

        $this->http = $http ?? new Client([
            'base_uri'    => $endpoint,
            'timeout'     => 30,
            'http_errors' => false,
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function listDnsRecords(string $zoneName): array
    {
        $data = $this->authenticatedRequest('infoDnsRecords', ['domainname' => $zoneName]);
        return array_values(array_map(
            static fn(mixed $record): array => (array) $record,
            (array) ($data['dnsrecords'] ?? []),
        ));
    }

    /** @return array<string, mixed> */
    public function getDnsZone(string $zoneName): array
    {
        return $this->authenticatedRequest('infoDnsZone', ['domainname' => $zoneName]);
    }

    /** @param list<array<string, mixed>> $records */
    public function replaceDnsRecords(string $zoneName, array $records): void
    {
        $this->authenticatedRequest('updateDnsRecords', [
            'domainname'   => $zoneName,
            'dnsrecordset' => ['dnsrecords' => array_values($records)],
        ]);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function authenticatedRequest(string $action, array $params): array
    {
        $session = $this->login();
        try {
            return $this->call($action, $params + [
                'customernumber' => $this->customerNumber,
                'apikey'         => $this->apiKey,
                'apisessionid'   => $session,
            ]);
        } finally {
            try {
                $this->call('logout', [
                    'customernumber' => $this->customerNumber,
                    'apikey'         => $this->apiKey,
                    'apisessionid'   => $session,
                ]);
            } catch (NetcupApiException) {
                // A successful DNS mutation must not become a failure merely
                // because the best-effort session cleanup was unavailable.
            }
        }
    }

    private function login(): string
    {
        $data = $this->call('login', [
            'customernumber' => $this->customerNumber,
            'apikey'         => $this->apiKey,
            'apipassword'    => $this->apiPassword,
        ]);
        $session = (string) ($data['apisessionid'] ?? '');
        if ($session === '') {
            throw new NetcupApiException('Netcup login lieferte keine API-Session-ID.');
        }
        return $session;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function call(string $action, array $params): array
    {
        try {
            $response = $this->http->request('POST', '', [
                RequestOptions::HEADERS => ['Accept' => 'application/json'],
                RequestOptions::JSON    => ['action' => $action, 'param' => $params],
            ]);
        } catch (GuzzleException $e) {
            throw new NetcupApiException('Netcup API-Aufruf fehlgeschlagen: ' . $e->getMessage(), 0, $e);
        }

        /** @var mixed $decoded */
        $decoded = json_decode((string) $response->getBody(), true);
        if (!is_array($decoded)) {
            throw new NetcupApiException(sprintf('Netcup API lieferte keine JSON-Antwort (HTTP %d).', $response->getStatusCode()));
        }

        $status = (int) ($decoded['statuscode'] ?? 0);
        if ($response->getStatusCode() >= 400 || $status !== 2000) {
            $message = (string) ($decoded['longmessage'] ?? $decoded['shortmessage'] ?? 'Unbekannter Fehler');
            throw new NetcupApiException(sprintf('Netcup API %s fehlgeschlagen (%d): %s', $action, $status, $message));
        }

        return (array) ($decoded['responsedata'] ?? []);
    }
}

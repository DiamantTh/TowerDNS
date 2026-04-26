<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Provider\Cloudflare;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;

/**
 * Thin HTTP client for the Cloudflare v4 REST API.
 *
 * Handles Bearer-token authentication, JSON decoding, error mapping, and
 * automatic pagination for list endpoints. Zone names (not CF UUIDs) are
 * used as the external zone identifier throughout TowerDNS; UUIDs are
 * resolved lazily and cached in-memory for the lifetime of the client.
 *
 * @see https://developers.cloudflare.com/api/
 */
final class CloudflareApiClient
{
    private const string BASE_URI = 'https://api.cloudflare.com/client/v4/';

    private readonly ClientInterface $http;

    /** @var array<string, string> zone-name → CF zone UUID cache */
    private array $zoneUuidCache = [];

    /** @var string|null cached account ID */
    private ?string $accountId = null;

    public function __construct(
        private readonly string $apiToken,
        ?ClientInterface $http = null,
    ) {
        if ($apiToken === '') {
            throw new CloudflareApiException('Cloudflare API-Token darf nicht leer sein.');
        }

        $this->http = $http ?? new Client([
            'base_uri'    => self::BASE_URI,
            'timeout'     => 30,
            'http_errors' => false,
        ]);
    }

    // ── Zone operations ───────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    public function listZones(): array
    {
        $results = [];
        $page    = 1;

        do {
            $data  = $this->get('zones', ['page' => $page, 'per_page' => 50]);
            $batch = (array) ($data['result'] ?? []);
            foreach ($batch as $item) {
                $results[] = (array) $item;
            }
            $total = (int) (((array) ($data['result_info'] ?? []))['total_pages'] ?? 1);
            $page++;
        } while ($page <= $total);

        return $results;
    }

    /** @return array<string, mixed> */
    public function createZone(string $zoneName): array
    {
        $accountId = $this->getAccountId();
        $data      = $this->post('zones', [
            'name'       => $zoneName,
            'account'    => ['id' => $accountId],
            'jump_start' => false,
        ]);
        return (array) ($data['result'] ?? []);
    }

    public function deleteZone(string $zoneName): void
    {
        $uuid = $this->resolveZoneUuid($zoneName);
        $this->apiRequest('DELETE', 'zones/' . rawurlencode($uuid), null, [], false);
        unset($this->zoneUuidCache[$zoneName]);
    }

    // ── DNS record operations ─────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    public function listDnsRecords(string $zoneName): array
    {
        $uuid    = $this->resolveZoneUuid($zoneName);
        $results = [];
        $page    = 1;

        do {
            $data  = $this->get('zones/' . rawurlencode($uuid) . '/dns_records', ['page' => $page, 'per_page' => 100]);
            $batch = (array) ($data['result'] ?? []);
            foreach ($batch as $item) {
                $results[] = (array) $item;
            }
            $total = (int) (((array) ($data['result_info'] ?? []))['total_pages'] ?? 1);
            $page++;
        } while ($page <= $total);

        return $results;
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public function createDnsRecord(string $zoneName, array $record): array
    {
        $uuid = $this->resolveZoneUuid($zoneName);
        $data = $this->post('zones/' . rawurlencode($uuid) . '/dns_records', $record);
        return (array) ($data['result'] ?? []);
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public function updateDnsRecord(string $zoneName, string $cfRecordId, array $record): array
    {
        $uuid = $this->resolveZoneUuid($zoneName);
        $data = $this->patch(
            'zones/' . rawurlencode($uuid) . '/dns_records/' . rawurlencode($cfRecordId),
            $record,
        );
        return (array) ($data['result'] ?? []);
    }

    public function deleteDnsRecord(string $zoneName, string $cfRecordId): void
    {
        $uuid = $this->resolveZoneUuid($zoneName);
        $this->apiRequest(
            'DELETE',
            'zones/' . rawurlencode($uuid) . '/dns_records/' . rawurlencode($cfRecordId),
            null,
            [],
            false,
        );
    }

    // ── DNSSEC operations ─────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    public function getDnssec(string $zoneName): array
    {
        $uuid = $this->resolveZoneUuid($zoneName);
        $data = $this->get('zones/' . rawurlencode($uuid) . '/dnssec');
        return (array) ($data['result'] ?? []);
    }

    /**
     * @param array<string, mixed> $payload  e.g. ['status' => 'active'] or ['status' => 'disabled']
     * @return array<string, mixed>
     */
    public function patchDnssec(string $zoneName, array $payload): array
    {
        $uuid = $this->resolveZoneUuid($zoneName);
        $data = $this->patch('zones/' . rawurlencode($uuid) . '/dnssec', $payload);
        return (array) ($data['result'] ?? []);
    }

    // ── Internal helpers ──────────────────────────────────────────────────────

    /**
     * Resolve a human-readable zone name to the Cloudflare internal zone UUID.
     * Results are cached for the lifetime of this client instance.
     */
    public function resolveZoneUuid(string $zoneName): string
    {
        if (isset($this->zoneUuidCache[$zoneName])) {
            return $this->zoneUuidCache[$zoneName];
        }

        $data   = $this->get('zones', ['name' => $zoneName, 'per_page' => 1]);
        $result = (array) ($data['result'][0] ?? []);
        $uuid   = (string) ($result['id'] ?? '');

        if ($uuid === '') {
            throw new CloudflareApiException(sprintf('Cloudflare-Zone "%s" nicht gefunden.', $zoneName));
        }

        $this->zoneUuidCache[$zoneName] = $uuid;
        return $uuid;
    }

    private function getAccountId(): string
    {
        if ($this->accountId !== null) {
            return $this->accountId;
        }

        $data     = $this->get('accounts', ['per_page' => 1]);
        $accounts = (array) ($data['result'] ?? []);

        if ($accounts === []) {
            throw new CloudflareApiException('Kein Cloudflare-Konto für diesen API-Token gefunden.');
        }

        $first           = (array) $accounts[0];
        $this->accountId = (string) ($first['id'] ?? '');
        return $this->accountId;
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query = []): array
    {
        return $this->apiRequest('GET', $path, null, $query);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function post(string $path, array $body): array
    {
        return $this->apiRequest('POST', $path, $body);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function patch(string $path, array $body): array
    {
        return $this->apiRequest('PATCH', $path, $body);
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function apiRequest(
        string $method,
        string $path,
        ?array $body = null,
        array  $query = [],
        bool   $decode = true,
    ): array {
        $options = [
            RequestOptions::HEADERS => [
                'Authorization' => 'Bearer ' . $this->apiToken,
                'Content-Type'  => 'application/json',
            ],
        ];

        if ($query !== []) {
            $options[RequestOptions::QUERY] = $query;
        }
        if ($body !== null) {
            $options[RequestOptions::JSON] = $body;
        }

        try {
            $response = $this->http->request($method, $path, $options);
        } catch (BadResponseException $e) {
            $raw = (string) $e->getResponse()->getBody();
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($raw, true) ?? [];
            $errors  = (array) ($decoded['errors'] ?? []);
            $msg     = $errors !== []
                ? (string) (((array) $errors[0])['message'] ?? 'Unbekannter Fehler')
                : 'HTTP ' . $e->getResponse()->getStatusCode();
            throw new CloudflareApiException('Cloudflare-API-Fehler: ' . $msg, $e->getCode(), $e);
        } catch (GuzzleException $e) {
            throw new CloudflareApiException('Cloudflare-Verbindungsfehler: ' . $e->getMessage(), 0, $e);
        }

        if (!$decode) {
            return [];
        }

        /** @var array<string, mixed> $data */
        $data = json_decode((string) $response->getBody(), true) ?? [];

        if (!($data['success'] ?? false)) {
            $errors = (array) ($data['errors'] ?? []);
            $msg    = $errors !== []
                ? (string) (((array) $errors[0])['message'] ?? 'Unbekannter Fehler')
                : 'Cloudflare-API-Fehler ohne Details';
            throw new CloudflareApiException('Cloudflare-API-Fehler: ' . $msg);
        }

        return $data;
    }
}

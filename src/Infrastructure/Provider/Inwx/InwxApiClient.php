<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Provider\Inwx;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;

/**
 * Thin JSON-RPC client for the INWX nameserver API.
 *
 * INWX uses a proprietary JSON-RPC dialect (no jsonrpc version field, no id
 * field in responses) at https://api.inwx.com/jsonrpc/. Sessions are managed
 * via the domrobot cookie set on successful login.
 *
 * A login/logout pair is performed transparently on each client creation.
 * This fits PHP-FPM's per-request execution model. For long-lived processes
 * the session remains valid until the server-side timeout (~1 h).
 *
 * @see https://www.inwx.de/de/api-documentation
 */
final class InwxApiClient
{
    private const API_URL = 'https://api.inwx.com/jsonrpc/';

    private ClientInterface $http;
    private CookieJar $jar;
    private bool $loggedIn = false;
    private int $requestId = 1;

    public function __construct(
        private readonly string $username,
        private readonly string $password,
        ?ClientInterface $http = null,
    ) {
        if ($username === '' || $password === '') {
            throw new InwxApiException('INWX-Benutzername und Passwort dürfen nicht leer sein.');
        }

        $this->jar  = new CookieJar();
        $this->http = $http ?? new Client([
            'timeout'     => 30,
            'http_errors' => false,
            'cookies'     => $this->jar,
        ]);
    }

    // ── Zone operations ───────────────────────────────────────────────────────

    /**
     * @return list<array<string, mixed>>
     */
    public function listZones(): array
    {
        $this->ensureLoggedIn();
        $result  = $this->call('nameserver.list', ['pagelimit' => 100, 'page' => 1, 'wide' => '1']);
        $entries = (array) ($result['domains'] ?? $result['list'] ?? []);
        return array_values(array_map(fn($e) => (array) $e, $entries));
    }

    /**
     * @return array<string, mixed>
     */
    public function createZone(string $domainName): array
    {
        $this->ensureLoggedIn();
        return (array) $this->call('nameserver.create', [
            'domain'   => $domainName,
            'type'     => 'MASTER',
            'ns'       => ['ns.inwx.de', 'ns2.inwx.de'],
            'soaemail' => 'hostmaster@' . $domainName,
        ]);
    }

    public function deleteZone(string $domainName): void
    {
        $this->ensureLoggedIn();
        $this->call('nameserver.delete', ['domain' => $domainName]);
    }

    // ── Record operations ─────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function getZoneInfo(string $domainName): array
    {
        $this->ensureLoggedIn();
        return (array) $this->call('nameserver.info', ['domain' => $domainName]);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function createRecord(array $params): array
    {
        $this->ensureLoggedIn();
        return (array) $this->call('nameserver.createRecord', $params);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function updateRecord(array $params): array
    {
        $this->ensureLoggedIn();
        return (array) $this->call('nameserver.updateRecord', $params);
    }

    public function deleteRecord(int $recordId): void
    {
        $this->ensureLoggedIn();
        $this->call('nameserver.deleteRecord', ['id' => $recordId]);
    }

    // ── DNSSEC operations ─────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function getDnsKeyInfo(string $domainName): array
    {
        $this->ensureLoggedIn();
        try {
            return (array) $this->call('nameserver.dnskeyInfo', ['domain' => $domainName]);
        } catch (InwxApiException) {
            // Provider may not support DNSSEC key info for this domain.
            return [];
        }
    }

    public function activateDnssec(string $domainName): void
    {
        $this->ensureLoggedIn();
        $this->call('nameserver.activateDnssec', ['domain' => $domainName]);
    }

    public function deactivateDnssec(string $domainName): void
    {
        $this->ensureLoggedIn();
        $this->call('nameserver.deactivateDnssec', ['domain' => $domainName]);
    }

    // ── Session management ────────────────────────────────────────────────────

    private function ensureLoggedIn(): void
    {
        if ($this->loggedIn) {
            return;
        }

        $response = $this->rawCall([
            'method' => 'account.login',
            'params' => [
                'lang' => 'en',
                'user' => $this->username,
                'pass' => $this->password,
            ],
        ]);

        $code = (int) ($response['code'] ?? 0);
        if ($code !== 1000) {
            throw new InwxApiException(sprintf(
                'INWX-Login fehlgeschlagen (Code %d): %s',
                $code,
                (string) ($response['msg'] ?? 'Unbekannter Fehler'),
            ));
        }

        $this->loggedIn = true;
    }

    /**
     * Execute an INWX API method and return the resData payload.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function call(string $method, array $params = []): array
    {
        $response = $this->rawCall([
            'method' => $method,
            'params' => $params,
        ]);

        $code = (int) ($response['code'] ?? 0);

        if ($code < 1000 || $code >= 2000) {
            throw new InwxApiException(sprintf(
                'INWX-API-Fehler bei "%s" (Code %d): %s',
                $method,
                $code,
                (string) ($response['msg'] ?? 'Unbekannter Fehler'),
            ));
        }

        return (array) ($response['resData'] ?? []);
    }

    /**
     * Perform a raw HTTP POST and return the decoded response body.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function rawCall(array $payload): array
    {
        $payload['id'] = $this->requestId++;

        try {
            $response = $this->http->request('POST', self::API_URL, [
                RequestOptions::HEADERS     => ['Content-Type' => 'application/json'],
                RequestOptions::JSON        => $payload,
                RequestOptions::COOKIES     => $this->jar,
            ]);
        } catch (GuzzleException $e) {
            throw new InwxApiException('INWX-Verbindungsfehler: ' . $e->getMessage(), 0, $e);
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getBody(), true) ?? [];

        if ($decoded === []) {
            throw new InwxApiException('INWX-API lieferte keine gültige JSON-Antwort.');
        }

        return $decoded;
    }
}

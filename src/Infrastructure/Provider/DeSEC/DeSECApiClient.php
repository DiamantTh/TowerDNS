<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Provider\DeSEC;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;

/**
 * Thin HTTP client for the public deSEC v1 API.
 *
 * Ported from the original desec-manager project (App\DeSEC\DeSECClient) and
 * adapted to TowerDNS conventions:
 *  - lives in the Infrastructure layer below the provider adapter,
 *  - throws a TowerDNS-typed exception, and
 *  - is injectable for testing (HTTP client may be passed in).
 */
final class DeSECApiClient
{
    private const DEFAULT_BASE_URL = 'https://desec.io/api/v1/domains/';

    private ClientInterface $http;

    /** @var array<string, string> */
    private array $headers;

    public function __construct(string $token, ?ClientInterface $http = null, string $baseUrl = self::DEFAULT_BASE_URL)
    {
        if ($token === '') {
            throw new DeSECApiException('deSEC API-Token darf nicht leer sein.');
        }

        $this->http = $http ?? new Client([
            'base_uri'    => $baseUrl,
            'timeout'     => 30,
            'http_errors' => true,
        ]);

        $this->headers = [
            'Authorization' => 'Token ' . $token,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listDomains(): array
    {
        return $this->fetchAllPages('');
    }

    /**
     * @return array<string, mixed>
     */
    public function createDomain(string $domainName): array
    {
        if ($domainName === '') {
            throw new DeSECApiException('Domain-Name darf nicht leer sein.');
        }

        /** @var array<string, mixed> $result */
        $result = $this->request('POST', '', ['name' => $domainName]);
        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDomain(string $domainName): array
    {
        /** @var array<string, mixed> $result */
        $result = $this->request('GET', $this->domainPath($domainName));
        return $result;
    }

    public function deleteDomain(string $domainName): bool
    {
        $response = $this->request('DELETE', $this->domainPath($domainName), null, false);
        \assert($response instanceof ResponseInterface);
        return $response->getStatusCode() === 204;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getRRSets(string $domainName): array
    {
        return $this->fetchAllPages($this->domainPath($domainName, 'rrsets/'));
    }

    /**
     * @return array<string, mixed>
     */
    public function getRRSet(string $domainName, string $subname, string $type): array
    {
        $sub = $subname === '' ? '@' : $subname;
        /** @var array<string, mixed> $result */
        $result = $this->request(
            'GET',
            $this->domainPath($domainName, sprintf('rrsets/%s/%s/', $sub, strtoupper($type))),
        );
        return $result;
    }

    /**
     * @param list<string> $records
     * @return array<string, mixed>
     */
    public function createRRSet(string $domainName, string $subname, string $type, array $records, int $ttl = 3600): array
    {
        /** @var array<string, mixed> $result */
        $result = $this->request('POST', $this->domainPath($domainName, 'rrsets/'), [
            'subname' => $subname,
            'type'    => strtoupper($type),
            'records' => $records,
            'ttl'     => $ttl,
        ]);
        return $result;
    }

    /**
     * @param list<string> $records
     * @return array<string, mixed>
     */
    public function modifyRRSet(string $domainName, string $subname, string $type, array $records, int $ttl = 3600): array
    {
        $sub = $subname === '' ? '@' : $subname;
        /** @var array<string, mixed> $result */
        $result = $this->request(
            'PATCH',
            $this->domainPath($domainName, sprintf('rrsets/%s/%s/', $sub, strtoupper($type))),
            [
                'records' => $records,
                'ttl'     => $ttl,
            ],
        );
        return $result;
    }

    public function deleteRRSet(string $domainName, string $subname, string $type): bool
    {
        $sub = $subname === '' ? '@' : $subname;
        $response = $this->request(
            'DELETE',
            $this->domainPath($domainName, sprintf('rrsets/%s/%s/', $sub, strtoupper($type))),
            null,
            false,
        );
        \assert($response instanceof ResponseInterface);
        return $response->getStatusCode() === 204;
    }

    public function exportZonefile(string $domainName): string
    {
        $response = $this->request('GET', $this->domainPath($domainName, 'zonefile/'), null, false);
        \assert($response instanceof ResponseInterface);
        return $response->getBody()->getContents();
    }

    private function domainPath(string $domain, string $suffix = ''): string
    {
        return $domain . '/' . $suffix;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchAllPages(string $endpoint): array
    {
        $all = [];
        $cursor = null;

        do {
            $options = [RequestOptions::HEADERS => $this->headers];
            if ($cursor !== null) {
                $options[RequestOptions::QUERY] = ['cursor' => $cursor];
            }

            try {
                $response = $this->http->request('GET', $endpoint, $options);
            } catch (GuzzleException $e) {
                throw new DeSECApiException('deSEC API-Aufruf fehlgeschlagen: ' . $e->getMessage(), (int) $e->getCode(), $e);
            }

            /** @var mixed $body */
            $body = json_decode($response->getBody()->getContents(), true);
            if (is_array($body)) {
                /** @var list<array<string, mixed>> $body */
                $all = array_merge($all, $body);
            }

            $cursor = $this->extractNextCursor($response->getHeader('Link'));
        } while ($cursor !== null);

        return $all;
    }

    /**
     * @param list<string> $linkHeaders
     */
    private function extractNextCursor(array $linkHeaders): ?string
    {
        $pattern = '/<[^>]+[?&]cursor=([^>&\s]*)>[^;]*;\s*rel="next"/';
        foreach ($linkHeaders as $header) {
            if (preg_match($pattern, $header, $matches) === 1) {
                return urldecode($matches[1]);
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed>|list<array<string, mixed>>|null $data
     * @return mixed
     */
    private function request(string $method, string $endpoint, ?array $data = null, bool $decodeJson = true): mixed
    {
        try {
            $options = [RequestOptions::HEADERS => $this->headers];
            if ($data !== null) {
                $options[RequestOptions::JSON] = $data;
            }
            $response = $this->http->request($method, $endpoint, $options);

            return $decodeJson
                ? json_decode($response->getBody()->getContents(), true)
                : $response;
        } catch (GuzzleException $e) {
            throw new DeSECApiException('deSEC API-Aufruf fehlgeschlagen: ' . $e->getMessage(), (int) $e->getCode(), $e);
        }
    }
}

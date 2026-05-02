<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Security;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TowerDNS\Application\Services\BreachedPasswordCheckerInterface;

/**
 * HaveIBeenPwned "Pwned Passwords" Range-API client.
 *
 * Uses k-anonymity: only the first 5 chars of the SHA-1 hash are sent.
 * The full password (and its full hash) NEVER leaves the server.
 *
 * Endpoint:  https://api.pwnedpasswords.com/range/{first5sha1}
 * Docs:      https://haveibeenpwned.com/API/v3#PwnedPasswords
 *
 * Behaviour on transient HTTP/network errors is governed by `failOpen`:
 *   true  → return 0 (do not block the user) and log a warning
 *   false → re-throw, the policy treats the password as breached
 */
final readonly class HibpRangePasswordChecker implements BreachedPasswordCheckerInterface
{
    private const string ENDPOINT = 'https://api.pwnedpasswords.com/range/';

    public function __construct(
        private ClientInterface $http,
        private bool            $failOpen = true,
        private LoggerInterface $logger = new NullLogger(),
        private float           $timeout = 3.0,
    ) {}

    public function timesSeen(string $password): int
    {
        if ($password === '') {
            return 0;
        }

        $sha1   = strtoupper(sha1($password));
        $prefix = substr($sha1, 0, 5);
        $suffix = substr($sha1, 5);

        try {
            $response = $this->http->request('GET', self::ENDPOINT . $prefix, [
                RequestOptions::HEADERS         => [
                    'Add-Padding' => 'true', // server adds noise to defeat traffic analysis
                    'User-Agent'  => 'TowerDNS-HIBP-Check',
                ],
                RequestOptions::TIMEOUT         => $this->timeout,
                RequestOptions::CONNECT_TIMEOUT => $this->timeout,
                RequestOptions::HTTP_ERRORS     => true,
            ]);
        } catch (GuzzleException $e) {
            $this->logger->warning('HIBP range lookup failed', ['error' => $e->getMessage()]);
            if ($this->failOpen) {
                return 0;
            }
            // fail-closed: pretend the password is breached so the policy rejects it.
            return 1;
        }

        $body = (string) $response->getBody();
        foreach (preg_split('/\r?\n/', $body) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            // format:  HASH_SUFFIX:COUNT
            $parts = explode(':', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }
            if (strcasecmp($parts[0], $suffix) === 0) {
                $count = (int) $parts[1];
                // Padding rows are returned with count 0 — ignore those.
                return $count > 0 ? $count : 0;
            }
        }

        return 0;
    }
}

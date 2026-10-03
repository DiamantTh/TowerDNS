<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Module\INWX;

use INWX\CallFailedException;
use INWX\Domrobot;

/** DNS-only facade over the maintained INWX Domrobot SDK. */
final class INWXAPIClient
{
    private readonly Domrobot $sdk;
    private bool $loggedIn = false;

    public function __construct(
        private readonly string $username,
        private readonly string $password,
        ?Domrobot $sdk = null,
        private readonly ?string $sharedSecret = null,
    ) {
        if ($username === '' || $password === '') {
            throw new INWXAPIException('INWX-Benutzername und Passwort dürfen nicht leer sein.');
        }
        $this->sdk = $sdk ?? new Domrobot()->useLive()->useJson()->setDebug(false);
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
        return array_values(array_map(fn($e): array => (array) $e, $entries));
    }

    /**
     * @return array<string, mixed>
     * @psalm-suppress PossiblyUnusedReturnValue Adapter workflows verify mutations by read-back; retain the raw response at this API boundary.
     */
    public function createZone(string $domainName): array
    {
        $this->ensureLoggedIn();
        return $this->call('nameserver.create', [
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
        return $this->call('nameserver.info', ['domain' => $domainName]);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     * @psalm-suppress PossiblyUnusedReturnValue Adapter workflows verify mutations by read-back; retain the raw response at this API boundary.
     */
    public function createRecord(array $params): array
    {
        $this->ensureLoggedIn();
        return $this->call('nameserver.createRecord', $params);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     * @psalm-suppress PossiblyUnusedReturnValue Adapter workflows verify mutations by read-back; retain the raw response at this API boundary.
     */
    public function updateRecord(array $params): array
    {
        $this->ensureLoggedIn();
        return $this->call('nameserver.updateRecord', $params);
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
            return $this->call('nameserver.dnskeyInfo', ['domain' => $domainName]);
        } catch (INWXAPIException) {
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

        try {
            $response = $this->sdk->login($this->username, $this->password, $this->sharedSecret);
        } catch (CallFailedException $e) {
            throw new INWXAPIException('INWX-Login konnte nicht ausgeführt werden.', 0, $e);
        }
        $tfaIndicator = $response['resData']['tfa'] ?? false;
        $tfaRequired  = $tfaIndicator === true || $tfaIndicator === 1
                                               || (is_string($tfaIndicator) && $tfaIndicator !== '' && $tfaIndicator !== '0');
        if ($tfaRequired && ($this->sharedSecret === null || $this->sharedSecret === '')) {
            throw new INWXAPIException('INWX erfordert einen konfigurierten zweiten Faktor.');
        }

        $code = (int) ($response['code'] ?? 0);
        if ($code !== 1000) {
            throw new INWXAPIException(sprintf(
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
        $separator = strpos($method, '.');
        if ($separator === false || $separator === 0 || $separator === strlen($method) - 1) {
            throw new \InvalidArgumentException('INWX API method must use object.operation notation.');
        }
        $object    = substr($method, 0, $separator);
        $operation = substr($method, $separator + 1);
        try {
            $response = $this->sdk->call($object, $operation, $params);
        } catch (CallFailedException $e) {
            throw new INWXAPIException('INWX-DNS-Aufruf fehlgeschlagen: ' . $method, 0, $e);
        }

        $code = (int) ($response['code'] ?? 0);

        if ($code < 1000 || $code >= 2000) {
            throw new INWXAPIException(sprintf(
                'INWX-API-Fehler bei "%s" (Code %d): %s',
                $method,
                $code,
                (string) ($response['msg'] ?? 'Unbekannter Fehler'),
            ));
        }

        return (array) ($response['resData'] ?? []);
    }
}

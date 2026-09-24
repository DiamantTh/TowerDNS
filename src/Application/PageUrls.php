<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application;

/** Public page URL templates and safe same-origin redirect normalization. */
final readonly class PageUrls
{
    public function __construct(private string $style = 'php') {}

    /** @return array<string, string> */
    public function templates(): array
    {
        if ($this->style === 'path') {
            return [
                'dashboard'      => '/', 'login' => '/login', 'admin' => '/admin',
                'users'          => '/users', 'user' => '/users/{id}',
                'roles'          => '/roles', 'role' => '/roles/{id}',
                'accounts'       => '/accounts', 'account' => '/accounts/{id}',
                'accountMembers' => '/accounts/{id}/members', 'accountProviders' => '/accounts/{id}/providers',
                'zones'          => '/zones', 'accountZones' => '/accounts/{account}/zones',
                'zoneDelete'     => '/accounts/{account}/zones/{zone}/delete',
                'records'        => '/accounts/{account}/zones/{zone}',
                'rrsetReplace'   => '/accounts/{account}/zones/{zone}/rrsets',
                'rrsetDelete'    => '/accounts/{account}/zones/{zone}/rrsets/{owner}/{type}/delete',
                'record'         => '/accounts/{account}/zones/{zone}/records/{record}/edit',
                'dnssec'         => '/accounts/{account}/zones/{zone}/dnssec',
                'profile'        => '/profile', 'settings' => '/settings',
                'schema'         => '/settings/schema',
            ];
        }

        return [
            'dashboard'      => '/index.php', 'login' => '/login.php', 'admin' => '/admin.php',
            'users'          => '/users.php', 'user' => '/users.php?id={id}',
            'roles'          => '/roles.php', 'role' => '/roles.php?id={id}',
            'accounts'       => '/accounts.php', 'account' => '/accounts.php?id={id}',
            'accountMembers' => '/accounts.php?id={id}&view=members', 'accountProviders' => '/accounts.php?id={id}&view=providers',
            'zones'          => '/zones.php', 'accountZones' => '/zones.php?account={account}',
            'zoneDelete'     => '/zones.php?account={account}&zone={zone}&action=delete',
            'records'        => '/records.php?account={account}&zone={zone}',
            'rrsetReplace'   => '/records.php?account={account}&zone={zone}&action=replace',
            'rrsetDelete'    => '/records.php?account={account}&zone={zone}&action=delete-rrset&owner={owner}&type={type}',
            'record'         => '/records.php?account={account}&zone={zone}&record={record}',
            'dnssec'         => '/dnssec.php?account={account}&zone={zone}',
            'profile'        => '/profile.php', 'settings' => '/settings.php',
            'schema'         => '/settings.php?view=schema',
        ];
    }

    /** @param array<string, string|int> $parameters */
    public function page(string $name, array $parameters = []): string
    {
        $template = $this->templates()[$name] ?? throw new \InvalidArgumentException('Unknown page.');
        foreach ($parameters as $key => $value) {
            $template = str_replace('{' . $key . '}', rawurlencode((string) $value), $template);
        }
        if (str_contains($template, '{')) {
            throw new \InvalidArgumentException('Missing page parameter.');
        }
        return $template;
    }

    public function redirect(string $location): string
    {
        if ($this->style === 'path' || !str_starts_with($location, '/') || str_starts_with($location, '//')) {
            return $location;
        }

        $query = parse_url($location, PHP_URL_QUERY);
        $path  = parse_url($location, PHP_URL_PATH);
        if (!is_string($path)) {
            return $location;
        }

        $name = match ($path) {
            '/'      => 'dashboard', '/login' => 'login', '/admin' => 'admin',
            '/users' => 'users', '/roles' => 'roles', '/accounts' => 'accounts',
            '/zones' => 'zones', '/profile' => 'profile', '/settings' => 'settings', '/settings/schema' => 'schema',
            default  => null,
        };
        $params = [];
        if ($name === null) {
            foreach ([
                '~^/users/([^/]+)$~'                                                   => 'user',
                '~^/roles/([^/]+)$~'                                                   => 'role',
                '~^/accounts/([1-9][0-9]*)$~'                                          => 'account',
                '~^/accounts/([1-9][0-9]*)/members$~'                                  => 'accountMembers',
                '~^/accounts/([1-9][0-9]*)/providers$~'                                => 'accountProviders',
                '~^/accounts/([1-9][0-9]*)/zones$~'                                    => 'accountZones',
                '~^/accounts/([1-9][0-9]*)/zones/([1-9][0-9]*)$~'                      => 'records',
                '~^/accounts/([1-9][0-9]*)/zones/([1-9][0-9]*)/records/([^/]+)/edit$~' => 'record',
                '~^/accounts/([1-9][0-9]*)/zones/([1-9][0-9]*)/dnssec$~'               => 'dnssec',
            ] as $pattern => $candidate) {
                if (preg_match($pattern, $path, $matches) !== 1) {
                    continue;
                }
                $name   = $candidate;
                $params = match ($candidate) {
                    'user', 'role', 'account', 'accountMembers', 'accountProviders' => ['id' => rawurldecode($matches[1])],
                    'accountZones'                                                  => ['account' => $matches[1]],
                    'record'                                                        => ['account' => $matches[1], 'zone' => $matches[2] ?? '', 'record' => rawurldecode($matches[3] ?? '')],
                    default                                                         => ['account' => $matches[1], 'zone' => $matches[2] ?? ''],
                };
                break;
            }
        }
        if ($name === null) {
            return $location;
        }

        $destination = $this->page($name, $params);
        return is_string($query) && $query !== '' ? $destination . (str_contains($destination, '?') ? '&' : '?') . $query : $destination;
    }
}

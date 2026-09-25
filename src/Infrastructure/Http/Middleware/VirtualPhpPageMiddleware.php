<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Middleware;

use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Application\PageUrls;

/** Maps allowlisted virtual PHP URLs to existing Mezzio routes before profile and route guards. */
final readonly class VirtualPhpPageMiddleware implements MiddlewareInterface
{
    private const array PAGES = ['login', 'admin', 'users', 'roles', 'accounts', 'zones', 'records', 'dnssec', 'profile', 'settings'];

    public function __construct(private PageUrls $urls = new PageUrls()) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path          = $request->getUri()->getPath();
        $externalQuery = $request->getUri()->getQuery();
        $requestUri    = $request->getServerParams()['REQUEST_URI'] ?? null;
        if (is_string($requestUri)) {
            // Diactoros uses rewritten QUERY_STRING for its URI, but the webserver
            // keeps the browser's original path and query in REQUEST_URI.
            [$externalPath, $externalQuery] = array_pad(explode('?', $requestUri, 2), 2, '');
            if ($externalPath !== $path) {
                return new EmptyResponse(400);
            }
        }
        // An entry supplied by the browser must never override the page name.
        foreach (explode('&', $externalQuery) as $part) {
            if (urldecode(explode('=', $part, 2)[0]) === 'entry') {
                return new EmptyResponse(400);
            }
        }
        if (!str_ends_with($path, '.php')) {
            return $this->normalizeRedirect($handler->handle($request));
        }

        $query = $request->getQueryParams();
        if ($path === '/index.php') {
            if (array_key_exists('entry', $query)) {
                return new EmptyResponse(400);
            }
        } else {
            $page = substr($path, 1, -4);
            if (!in_array($page, self::PAGES, true)) {
                return new EmptyResponse(404);
            }
            if (($query['entry'] ?? null) !== $page) {
                return new EmptyResponse(400);
            }
            unset($query['entry']);
            $request = $request->withQueryParams($query);
        }
        if ($this->hasDuplicateResourceParameter($externalQuery)) {
            return new EmptyResponse(400);
        }

        $id               = fn(string $key, bool $numeric = false): string|false|null => $this->id($query, $key, $numeric);
        $account          = $id('account', true);
        $zone             = $id('zone', true);
        $detail           = $id('id', $path === '/accounts.php');
        $record           = $id('record');
        $action           = $query['action'] ?? null;
        $view             = $query['view']   ?? null;
        $hasRrsetIdentity = array_key_exists('owner', $query) || array_key_exists('type', $query);

        if ($account === false || $zone === false || $detail === false || $record === false
                               || ($action !== null && !is_string($action)) || ($view !== null && !is_string($view))) {
            return new EmptyResponse(400);
        }
        if (($path === '/records.php' || $path === '/dnssec.php') && ($account === null || $zone === null)) {
            return new EmptyResponse(400);
        }
        if ($zone !== null && $account === null) {
            return new EmptyResponse(400);
        }

        $method = $request->getMethod();
        $target = match ($path) {
            '/index.php'    => '/',
            '/login.php'    => '/login',
            '/admin.php'    => '/admin',
            '/users.php'    => $detail  === null ? '/users' : '/users/' . rawurlencode($detail) . ($method === 'POST' && $action === 'delete' ? '/delete' : ''),
            '/roles.php'    => $detail  === null ? '/roles' : '/roles/' . rawurlencode($detail) . ($method === 'POST' && $action === 'delete' ? '/delete' : ''),
            '/accounts.php' => $detail  === null ? '/accounts' : '/accounts/' . $detail . ($view === 'members' || $view === 'providers' ? '/' . $view : ''),
            '/zones.php'    => $account === null ? '/zones' : ($zone === null ? '/accounts/' . $account . '/zones' : '/accounts/' . $account . '/zones/' . $zone . ($method === 'POST' && $action === 'delete' ? '/delete' : '')),
            '/records.php'  => $this->recordPath($account, $zone, $record, $action, $query, $method),
            '/dnssec.php'   => '/accounts/' . $account . '/zones/' . $zone . '/dnssec',
            '/profile.php'  => '/profile',
            '/settings.php' => $view === 'schema' ? '/settings/schema' : '/settings',
            default         => null,
        };

        if ($target === null) {
            return new EmptyResponse($path === '/records.php' ? 400 : 404);
        }
        if ($detail !== null && !in_array($path, ['/users.php', '/roles.php', '/accounts.php'], true)) {
            return new EmptyResponse(400);
        }
        if (($account !== null || $zone !== null) && !in_array($path, ['/zones.php', '/records.php', '/dnssec.php'], true)) {
            return new EmptyResponse(400);
        }
        if (($view !== null && (($path !== '/accounts.php' || !in_array($view, ['members', 'providers'], true)) && ($path !== '/settings.php' || $view !== 'schema')))
            || ($action !== null && !in_array($path, ['/users.php', '/roles.php', '/zones.php', '/records.php'], true))
            || ($action !== null && $path !== '/records.php' && $action !== 'delete')
            || ($action === 'delete' && in_array($path, ['/users.php', '/roles.php'], true) && $detail === null)
            || ($action === 'delete' && $path === '/zones.php' && $zone === null)
            || ($view !== null && $path === '/accounts.php' && $detail === null)
            || ($record !== null && $path !== '/records.php')
            || ($hasRrsetIdentity && ($path !== '/records.php' || $method !== 'POST' || $action !== 'delete-rrset'))
            || ($action === 'delete-rrset' && $record !== null)) {
            return new EmptyResponse(400);
        }

        if (!in_array($method, ['GET', 'HEAD', 'POST', 'OPTIONS'], true)) {
            return new EmptyResponse(405, ['Allow' => 'GET, HEAD, POST, OPTIONS']);
        }

        // Only the internal route path changes. Body, cookies, session and the
        // browser's query remain intact; the internal entry hint is removed.
        $routedUri = $request->getUri()->withPath($target)->withQuery($externalQuery);
        return $this->normalizeRedirect($handler->handle($request->withUri($routedUri)));
    }

    /** @param array<string, mixed> $query */
    private function recordPath(?string $account, ?string $zone, ?string $record, ?string $action, array $query, string $method): ?string
    {
        $base = '/accounts/' . $account . '/zones/' . $zone;
        if ($method === 'GET') {
            return $record === null && $action === null ? $base : ($record !== null && ($action === null || $action === 'edit') ? $base . '/records/' . rawurlencode($record) . '/edit' : null);
        }
        if ($method !== 'POST') {
            return $base;
        }
        return match ($action) {
            'create'       => $record === null ? $base . '/records' : null,
            'replace'      => $record === null ? $base . '/rrsets' : null,
            'update'       => $record !== null ? $base . '/records/' . rawurlencode($record) . '/update' : null,
            'delete'       => $record !== null ? $base . '/records/' . rawurlencode($record) . '/delete' : null,
            'delete-rrset' => $this->rrsetDeletePath($base, $query),
            default        => null,
        };
    }

    /** @param array<string, mixed> $query */
    private function rrsetDeletePath(string $base, array $query): ?string
    {
        $owner = $query['owner'] ?? null;
        $type  = $query['type']  ?? null;
        if (!is_string($owner) || !is_string($type) || strlen($owner) > 255 || preg_match('/^[A-Za-z0-9@._*-]*$/D', $owner) !== 1 || preg_match('/^[A-Za-z0-9-]{1,32}$/D', $type) !== 1) {
            return null;
        }
        return $base . '/rrsets/' . rawurlencode($owner) . '/' . rawurlencode($type) . '/delete';
    }

    private function normalizeRedirect(ResponseInterface $response): ResponseInterface
    {
        if (!$response->hasHeader('Location')) {
            return $response;
        }
        return $response->withHeader('Location', $this->urls->redirect($response->getHeaderLine('Location')));
    }

    /** @param array<string, mixed> $query */
    private function id(array $query, string $key, bool $numeric = false): string|false|null
    {
        if (!array_key_exists($key, $query)) {
            return null;
        }
        $value = $query[$key];
        if (!is_string($value) && !is_int($value)) {
            return false;
        }
        $value = (string) $value;
        if ($numeric) {
            return preg_match('/^[1-9][0-9]*$/D', $value) === 1
                && filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false ? $value : false;
        }
        return preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $value) === 1 ? $value : false;
    }

    private function hasDuplicateResourceParameter(string $rawQuery): bool
    {
        $seen = [];
        foreach (explode('&', $rawQuery) as $part) {
            $key = urldecode(explode('=', $part, 2)[0]);
            if (!in_array($key, ['id', 'account', 'zone', 'record', 'action', 'view', 'owner', 'type'], true)) {
                continue;
            }
            if (isset($seen[$key])) {
                return true;
            }
            $seen[$key] = true;
        }
        return false;
    }
}

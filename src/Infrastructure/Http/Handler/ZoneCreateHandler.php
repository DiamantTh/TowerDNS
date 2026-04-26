<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Mezzio\Csrf\CsrfGuardInterface;
use Mezzio\Csrf\CsrfMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Application\Exception\AuthorizationException;
use TowerDNS\Application\Services\DnsManagementService;
use TowerDNS\Domain\Auth\User;

/**
 * POST /zones/{provider} — creates a new zone for the given provider.
 */
final readonly class ZoneCreateHandler implements RequestHandlerInterface
{
    public function __construct(
        private DnsManagementService $dns,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var User $user */
        $user       = $request->getAttribute(User::class);
        $providerId = (string) $request->getAttribute('provider', '');

        /** @var CsrfGuardInterface $guard */
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        /** @var array<string, string> $body */
        $body  = (array) ($request->getParsedBody() ?? []);
        $token = (string) ($body['csrf_token'] ?? '');

        if (!$guard->validateToken($token)) {
            return new HtmlResponse('Ungültige Anfrage.', 400);
        }

        $zoneName = trim((string) ($body['zone_name'] ?? ''));

        if ($zoneName === '') {
            return new RedirectResponse('/zones?error=' . rawurlencode('Zonenname darf nicht leer sein.'));
        }

        try {
            $this->dns->createZone($user, $providerId, $zoneName);
        } catch (AuthorizationException) {
            return new RedirectResponse('/zones?error=' . rawurlencode('Keine Berechtigung zum Anlegen von Zonen.'));
        } catch (\Throwable $e) {
            return new RedirectResponse('/zones?error=' . rawurlencode($e->getMessage()));
        }

        return new RedirectResponse('/zones');
    }
}

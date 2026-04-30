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
use TowerDNS\Application\Services\AuditLogService;
use TowerDNS\Application\Services\DnsManagementService;
use TowerDNS\Domain\Auth\User;

/**
 * POST /zones/{provider}/{zone}/delete — removes a zone from the given provider.
 *
 * Uses POST instead of DELETE so that plain HTML forms can trigger the action
 * without JavaScript.
 */
final readonly class ZoneDeleteHandler implements RequestHandlerInterface
{
    public function __construct(
        private DnsManagementService $dns,
        private AuditLogService      $audit,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var CsrfGuardInterface $guard */
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $body  = (array) ($request->getParsedBody() ?? []);
        $token = (string) ($body['csrf_token'] ?? '');

        if (!$guard->validateToken($token)) {
            return new HtmlResponse('Ungültige Anfrage.', 400);
        }

        /** @var User $user */
        $user       = $request->getAttribute(User::class);
        $providerId = (string) $request->getAttribute('provider', '');
        $zoneId     = (string) $request->getAttribute('zone', '');

        try {
            $this->dns->deleteZone($user, $providerId, $zoneId);
            $this->audit->recordZoneDelete($request, $user->id, null, $zoneId, $zoneId);
        } catch (AuthorizationException) {
            return new RedirectResponse('/zones?error=' . rawurlencode('Keine Berechtigung zum Löschen von Zonen.'));
        } catch (\Throwable $e) {
            return new RedirectResponse('/zones?error=' . rawurlencode($e->getMessage()));
        }

        return new RedirectResponse('/zones');
    }
}

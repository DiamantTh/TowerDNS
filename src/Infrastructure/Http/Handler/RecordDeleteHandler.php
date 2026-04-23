<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Application\Exception\AuthorizationException;
use TowerDNS\Application\Services\DnsManagementService;
use TowerDNS\Domain\Auth\User;

/**
 * POST /zones/{provider}/{zone}/records/{record}/delete — removes a DNS record.
 *
 * Uses POST so plain HTML forms work without JavaScript.
 */
final class RecordDeleteHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly DnsManagementService $dns,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var User $user */
        $user       = $request->getAttribute(User::class);
        $providerId = (string) $request->getAttribute('provider', '');
        $zoneId     = (string) $request->getAttribute('zone', '');
        $recordId   = (string) $request->getAttribute('record', '');

        $back = '/zones/' . rawurlencode($providerId) . '/' . rawurlencode($zoneId);

        try {
            $this->dns->deleteRecord($user, $providerId, $zoneId, $recordId);
        } catch (AuthorizationException) {
            return new RedirectResponse($back . '?error=' . rawurlencode('Keine Berechtigung zum Löschen von Einträgen.'));
        } catch (\Throwable $e) {
            return new RedirectResponse($back . '?error=' . rawurlencode($e->getMessage()));
        }

        return new RedirectResponse($back);
    }
}

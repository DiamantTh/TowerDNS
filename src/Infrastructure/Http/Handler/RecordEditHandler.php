<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

use Laminas\Diactoros\Response\HtmlResponse;
use Mezzio\Csrf\CsrfGuardInterface;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Template\TemplateRendererInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Application\Exception\AuthorizationException;
use TowerDNS\Application\Services\DnsManagementService;
use TowerDNS\Domain\Auth\User;

/**
 * GET /zones/{provider}/{zone}/records/{record}/edit — pre-filled edit form.
 *
 * Loads all records for the zone and displays the one matching the route param,
 * since most DNS providers do not expose a single-record fetch endpoint.
 */
final readonly class RecordEditHandler implements RequestHandlerInterface
{
    public function __construct(
        private TemplateRendererInterface $renderer,
        private DnsManagementService      $dns,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var User $user */
        $user       = $request->getAttribute(User::class);
        $providerId = (string) $request->getAttribute('provider', '');
        $zoneId     = (string) $request->getAttribute('zone', '');
        $recordId   = (string) $request->getAttribute('record', '');

        /** @var CsrfGuardInterface $guard */
        $guard     = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $csrfToken = $guard->generateToken();

        try {
            $records = $this->dns->listRecords($user, $providerId, $zoneId);
        } catch (AuthorizationException $e) {
            return new HtmlResponse(
                $this->renderer->render('app::zones/record_edit', [
                    'user'       => $user,
                    'providerId' => $providerId,
                    'zoneId'     => $zoneId,
                    'record'     => null,
                    'csrfToken'  => $csrfToken,
                    'error'      => $e->getMessage(),
                ]),
                403,
            );
        } catch (\Throwable $e) {
            return new HtmlResponse(
                $this->renderer->render('app::zones/record_edit', [
                    'user'       => $user,
                    'providerId' => $providerId,
                    'zoneId'     => $zoneId,
                    'record'     => null,
                    'csrfToken'  => $csrfToken,
                    'error'      => $e->getMessage(),
                ]),
                500,
            );
        }
        $record = array_find($records, fn($r): bool => $r->id === $recordId);

        if ($record === null) {
            return new HtmlResponse(
                $this->renderer->render('app::zones/record_edit', [
                    'user'       => $user,
                    'providerId' => $providerId,
                    'zoneId'     => $zoneId,
                    'record'     => null,
                    'csrfToken'  => $csrfToken,
                    'error'      => 'Eintrag nicht gefunden.',
                ]),
                404,
            );
        }

        return new HtmlResponse(
            $this->renderer->render('app::zones/record_edit', [
                'user'       => $user,
                'providerId' => $providerId,
                'zoneId'     => $zoneId,
                'record'     => $record,
                'csrfToken'  => $csrfToken,
                'error'      => null,
            ]),
        );
    }
}

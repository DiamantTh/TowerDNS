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
use TowerDNS\Application\Services\DNSManagementService;
use TowerDNS\Domain\Auth\User;

/**
 * GET /zones/{provider}/{zone} — shows all DNS records for a zone.
 */
final readonly class RecordListHandler implements RequestHandlerInterface
{
    public function __construct(
        private TemplateRendererInterface $renderer,
        private DNSManagementService      $dns,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var User $user */
        $user       = $request->getAttribute(User::class);
        $providerId = (string) $request->getAttribute('provider', '');
        $zoneId     = (string) $request->getAttribute('zone', '');

        /** @var CsrfGuardInterface $guard */
        $guard     = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $csrfToken = $guard->generateToken();

        $flashError   = $request->getQueryParams()['error']   ?? null;
        $flashSuccess = $request->getQueryParams()['success'] ?? null;

        try {
            $rrsets = $this->dns->listRrsets($user, $providerId, $zoneId);
        } catch (AuthorizationException $e) {
            return new HtmlResponse(
                $this->renderer->render('app::zones/records', [
                    'user'       => $user,
                    'providerId' => $providerId,
                    'zoneId'     => $zoneId,
                    'rrsets'     => [],
                    'csrfToken'  => $csrfToken,
                    'error'      => $e->getMessage(),
                ]),
                403,
            );
        } catch (\Throwable $e) {
            return new HtmlResponse(
                $this->renderer->render('app::zones/records', [
                    'user'       => $user,
                    'providerId' => $providerId,
                    'zoneId'     => $zoneId,
                    'rrsets'     => [],
                    'csrfToken'  => $csrfToken,
                    'error'      => $e->getMessage(),
                ]),
                500,
            );
        }

        return new HtmlResponse(
            $this->renderer->render('app::zones/records', [
                'user'       => $user,
                'providerId' => $providerId,
                'zoneId'     => $zoneId,
                'rrsets'     => $rrsets,
                'csrfToken'  => $csrfToken,
                'error'      => is_string($flashError) ? $flashError : null,
                'success'    => is_string($flashSuccess) ? $flashSuccess : null,
            ]),
        );
    }
}

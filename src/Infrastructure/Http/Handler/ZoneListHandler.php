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
 * GET /zones — lists all configured providers with their zones.
 */
final readonly class ZoneListHandler implements RequestHandlerInterface
{
    public function __construct(
        private TemplateRendererInterface $renderer,
        private DnsManagementService      $dns,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var User $user */
        $user = $request->getAttribute(User::class);

        /** @var CsrfGuardInterface $guard */
        $guard     = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $csrfToken = $guard->generateToken();

        try {
            $providers = $this->dns->listProviders($user);
        } catch (AuthorizationException $e) {
            return new HtmlResponse(
                $this->renderer->render('app::zones/list', [
                    'user'            => $user,
                    'providers'       => [],
                    'zonesByProvider' => [],
                    'fetchErrors'     => [],
                    'csrfToken'       => $csrfToken,
                    'error'           => $e->getMessage(),
                ]),
                403,
            );
        }

        /** @var array<string, list<\TowerDNS\Domain\DNS\Zone>> $zonesByProvider */
        $zonesByProvider = [];
        /** @var array<string, string> $fetchErrors */
        $fetchErrors = [];

        foreach ($providers as $provider) {
            try {
                $zonesByProvider[$provider->id] = $this->dns->listZones($user, $provider->id);
            } catch (\Throwable $e) {
                $zonesByProvider[$provider->id] = [];
                $fetchErrors[$provider->id]     = $e->getMessage();
            }
        }

        $flashError = $request->getQueryParams()['error'] ?? null;

        return new HtmlResponse(
            $this->renderer->render('app::zones/list', [
                'user'            => $user,
                'providers'       => $providers,
                'zonesByProvider' => $zonesByProvider,
                'fetchErrors'     => $fetchErrors,
                'csrfToken'       => $csrfToken,
                'error'           => is_string($flashError) ? $flashError : null,
            ]),
        );
    }
}

<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Laminas\I18n\Translator\TranslatorInterface;
use Mezzio\Csrf\CsrfGuardInterface;
use Mezzio\Csrf\CsrfMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Application\Exception\AuthorizationException;
use TowerDNS\Application\Services\AuditLogService;
use TowerDNS\Application\Services\DNSManagementService;
use TowerDNS\Domain\Auth\User;

/**
 * POST /zones/{provider} — creates a new zone for the given provider.
 */
final readonly class ZoneCreateHandler implements RequestHandlerInterface
{
    public function __construct(
        private DNSManagementService $dns,
        private AuditLogService      $audit,
        private TranslatorInterface  $translator,
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
            return new HtmlResponse($this->translator->translate('http.error.invalid-request'), 400);
        }

        $zoneName = trim((string) ($body['zone_name'] ?? ''));

        if ($zoneName === '') {
            return new RedirectResponse('/zones?error=' . rawurlencode(
                $this->translator->translate('zones.error.name-required'),
            ));
        }

        try {
            $zone = $this->dns->createZone($user, $providerId, $zoneName);
            $this->audit->recordZoneCreate($request, $user->id, null, $zone->id, $zone->name);
        } catch (AuthorizationException) {
            return new RedirectResponse('/zones?error=' . rawurlencode(
                $this->translator->translate('zones.error.create-denied'),
            ));
        } catch (\Throwable) {
            return new RedirectResponse('/zones?error=' . rawurlencode(
                $this->translator->translate('zones.error.create-failed'),
            ));
        }

        return new RedirectResponse('/zones');
    }
}

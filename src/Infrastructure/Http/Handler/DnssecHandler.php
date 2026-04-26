<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
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
 * GET  /zones/{provider}/{zone}/dnssec — DNSSEC-Status anzeigen
 * POST /zones/{provider}/{zone}/dnssec — DNSSEC-Aktion ausführen (enable/disable/…)
 */
final readonly class DnssecHandler implements RequestHandlerInterface
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

        $back = '/zones/' . rawurlencode($providerId) . '/' . rawurlencode($zoneId) . '/dnssec';

        /** @var CsrfGuardInterface $guard */
        $guard     = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $csrfToken = $guard->generateToken();

        if ($request->getMethod() === 'POST') {
            return $this->handlePost($request, $guard, $user, $providerId, $zoneId, $back);
        }

        // GET — Flash-Nachrichten aus Query-Params lesen
        $queryParams  = $request->getQueryParams();
        $flashError   = isset($queryParams['error'])   && is_string($queryParams['error']) ? $queryParams['error'] : null;
        $flashSuccess = isset($queryParams['success']) && is_string($queryParams['success']) ? $queryParams['success'] : null;

        // Status laden
        try {
            $profile = $this->dns->getDnssecProfile($user, $providerId, $zoneId);
        } catch (AuthorizationException $e) {
            return new HtmlResponse(
                $this->renderer->render('app::zones/dnssec', [
                    'user'       => $user,
                    'providerId' => $providerId,
                    'zoneId'     => $zoneId,
                    'profile'    => null,
                    'csrfToken'  => $csrfToken,
                    'error'      => $e->getMessage(),
                    'success'    => null,
                ]),
                403,
            );
        } catch (\Throwable $e) {
            return new HtmlResponse(
                $this->renderer->render('app::zones/dnssec', [
                    'user'       => $user,
                    'providerId' => $providerId,
                    'zoneId'     => $zoneId,
                    'profile'    => null,
                    'csrfToken'  => $csrfToken,
                    'error'      => $e->getMessage(),
                    'success'    => null,
                ]),
            );
        }

        return new HtmlResponse(
            $this->renderer->render('app::zones/dnssec', [
                'user'       => $user,
                'providerId' => $providerId,
                'zoneId'     => $zoneId,
                'profile'    => $profile,
                'csrfToken'  => $csrfToken,
                'error'      => $flashError,
                'success'    => $flashSuccess,
            ]),
        );
    }

    private function handlePost(
        ServerRequestInterface $request,
        CsrfGuardInterface $guard,
        User $user,
        string $providerId,
        string $zoneId,
        string $back,
    ): ResponseInterface {
        /** @var array<string, string> $body */
        $body  = (array) ($request->getParsedBody() ?? []);
        $token = (string) ($body['csrf_token'] ?? '');

        if (!$guard->validateToken($token)) {
            return new RedirectResponse($back . '?error=' . rawurlencode('Ungültige Anfrage.'));
        }

        $action = trim((string) ($body['action'] ?? ''));
        if ($action === '') {
            return new RedirectResponse($back . '?error=' . rawurlencode('Keine Aktion angegeben.'));
        }

        // Einfache Payload-Weiterleitung (z. B. key.add braucht Typ-Felder)
        $payload = [];
        foreach ($body as $key => $value) {
            if ($key === 'csrf_token') {
                continue;
            }
            if ($key === 'action') {
                continue;
            }
            if (is_string($value) && $value !== '') {
                $payload[$key] = $value;
            }
        }

        try {
            $this->dns->executeDnssecAction($user, $providerId, $zoneId, $action, $payload);
        } catch (\Throwable $e) {
            return new RedirectResponse($back . '?error=' . rawurlencode($e->getMessage()));
        }

        $labels = [
            'enable'  => 'DNSSEC wurde aktiviert.',
            'disable' => 'DNSSEC wurde deaktiviert.',
        ];
        $success = $labels[$action] ?? 'DNSSEC-Aktion wurde ausgeführt.';

        return new RedirectResponse($back . '?success=' . rawurlencode($success));
    }
}

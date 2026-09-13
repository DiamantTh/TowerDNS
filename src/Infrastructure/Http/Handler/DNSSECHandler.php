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
use Mezzio\Template\TemplateRendererInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Application\Exception\AuthorizationException;
use TowerDNS\Application\Services\DNSManagementService;
use TowerDNS\Domain\Auth\User;

/**
 * GET  /zones/{provider}/{zone}/dnssec — DNSSEC-Status anzeigen
 * POST /zones/{provider}/{zone}/dnssec — DNSSEC-Aktion ausführen (enable/disable/…)
 */
final readonly class DNSSECHandler implements RequestHandlerInterface
{
    public function __construct(
        private TemplateRendererInterface $renderer,
        private DNSManagementService      $dns,
        private TranslatorInterface        $translator,
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
        } catch (AuthorizationException) {
            return new HtmlResponse(
                $this->renderer->render('app::zones/dnssec', [
                    'user'       => $user,
                    'providerId' => $providerId,
                    'zoneId'     => $zoneId,
                    'profile'    => null,
                    'csrfToken'  => $csrfToken,
                    'error'      => $this->translator->translate('dnssec.error.read-denied'),
                    'success'    => null,
                ]),
                403,
            );
        } catch (\Throwable) {
            return new HtmlResponse(
                $this->renderer->render('app::zones/dnssec', [
                    'user'       => $user,
                    'providerId' => $providerId,
                    'zoneId'     => $zoneId,
                    'profile'    => null,
                    'csrfToken'  => $csrfToken,
                    'error'      => $this->translator->translate('dnssec.error.read-failed'),
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
            return new RedirectResponse($back . '?error=' . rawurlencode($this->translator->translate('http.error.invalid-request')));
        }

        $action = trim((string) ($body['action'] ?? ''));
        if ($action === '') {
            return new RedirectResponse($back . '?error=' . rawurlencode($this->translator->translate('dnssec.error.action-required')));
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
        } catch (\Throwable) {
            return new RedirectResponse($back . '?error=' . rawurlencode($this->translator->translate('dnssec.error.action-failed')));
        }

        $labels = [
            'enable'  => 'dnssec.success.enabled',
            'disable' => 'dnssec.success.disabled',
        ];
        $success = $this->translator->translate($labels[$action] ?? 'dnssec.success.action-executed');

        return new RedirectResponse($back . '?success=' . rawurlencode($success));
    }
}

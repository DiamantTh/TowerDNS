<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Middleware;

use Laminas\Translator\TranslatorInterface;
use Mezzio\Csrf\CsrfGuardInterface;
use Mezzio\Csrf\CsrfMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Domain\Account\AdminImpersonationSession;
use TowerDNS\Domain\Auth\User;

/** Adds a persistent identity/scope warning and CSRF-protected exit action while an admin switch is active. */
final readonly class ImpersonationBannerMiddleware implements MiddlewareInterface
{
    public function __construct(
        private StreamFactoryInterface $streams,
        private TranslatorInterface $translator,
    ) {}

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response  = $handler->handle($request);
        $switch    = $request->getAttribute('impersonation_session');
        $actor     = $request->getAttribute('actor_user');
        $effective = $request->getAttribute('effective_user');
        $guard     = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);

        if ($request->getMethod() !== 'GET'
            || !$switch instanceof AdminImpersonationSession
            || !$actor instanceof User
            || !$effective instanceof User
            || !$guard instanceof CsrfGuardInterface
            || $response->getStatusCode() < 200
            || $response->getStatusCode() >= 300
            || !str_contains(strtolower($response->getHeaderLine('Content-Type')), 'text/html')) {
            return $response;
        }

        $body = (string) $response->getBody();
        if (!str_contains($body, 'id="towerdns-app"') || preg_match('/<body(?:\s[^>]*)?>/i', $body) !== 1) {
            return $response;
        }

        $message = htmlspecialchars(sprintf(
            $this->translator->translate('admin-switch.active-banner'),
            ($effective->displayName ?? '') !== '' ? $effective->displayName : $effective->email,
            ($actor->displayName ?? '') !== '' ? $actor->displayName : $actor->email,
        ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $token     = htmlspecialchars($guard->generateToken(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $label     = htmlspecialchars($this->translator->translate('admin-switch.end'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $banner    = '<aside class="impersonation-banner" role="alert"><p>' . $message . '</p><form method="post" action="/admin/switch/end"><input type="hidden" name="csrf_token" value="' . $token . '"><button class="button is-danger" type="submit">' . $label . '</button></form></aside>';
        $decorated = preg_replace('/(<body(?:\s[^>]*)?>)/i', '$1' . $banner, $body, 1);
        if (!is_string($decorated)) {
            return $response;
        }

        return $response
            ->withoutHeader('Content-Length')
            ->withBody($this->streams->createStream($decorated));
    }
}

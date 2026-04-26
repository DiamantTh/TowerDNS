<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

use Laminas\Diactoros\Response\RedirectResponse;
use Mezzio\Csrf\CsrfMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Application\Repository\WebAuthnCredentialRepositoryInterface;
use TowerDNS\Domain\Auth\User;

/**
 * POST /profile/webauthn/{credentialId}/delete
 *
 * Deletes one of the current user's WebAuthn credentials.
 * credentialId is base64url-encoded raw credential bytes.
 */
final class WebAuthnDeleteHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly WebAuthnCredentialRepositoryInterface $credentialRepo,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var \Mezzio\Csrf\CsrfGuardInterface $guard */
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);

        /** @var array<string, string> $body */
        $body  = (array) ($request->getParsedBody() ?? []);
        $token = (string) ($body['csrf_token'] ?? '');

        if (!$guard->validateToken($token)) {
            return new RedirectResponse('/profile/webauthn?error=' . rawurlencode('Ungültige Anfrage.'));
        }

        // credentialId in the URL is base64url-encoded raw bytes.
        /** @var array<string, string> $routeParams */
        $routeParams  = $request->getAttribute(\Mezzio\Router\RouteResult::class)?->getMatchedParams() ?? [];
        $credIdUrl    = (string) ($routeParams['credentialId'] ?? '');

        if ($credIdUrl === '') {
            return new RedirectResponse('/profile/webauthn?error=' . rawurlencode('Schlüssel nicht gefunden.'));
        }

        $credentialId = base64_decode(strtr($credIdUrl, '-_', '+/'), true);
        if ($credentialId === false) {
            return new RedirectResponse('/profile/webauthn?error=' . rawurlencode('Ungültige Schlüssel-ID.'));
        }

        /** @var User $currentUser */
        $currentUser = $request->getAttribute(User::class);

        $this->credentialRepo->delete($credentialId, $currentUser->id);

        return new RedirectResponse('/profile/webauthn?success=' . rawurlencode('Schlüssel wurde entfernt.'));
    }
}

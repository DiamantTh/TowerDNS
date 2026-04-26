<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

use Laminas\Diactoros\Response\HtmlResponse;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Template\TemplateRendererInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Application\Repository\WebAuthnCredentialRepositoryInterface;
use TowerDNS\Domain\Auth\User;

/**
 * GET /profile/webauthn
 *
 * Shows a list of all registered WebAuthn credentials for the current user
 * and provides the interface to add new keys or remove existing ones.
 */
final readonly class WebAuthnProfileHandler implements RequestHandlerInterface
{
    public function __construct(
        private TemplateRendererInterface              $renderer,
        private WebAuthnCredentialRepositoryInterface $webAuthn,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var User $currentUser */
        $currentUser = $request->getAttribute(User::class);

        /** @var \Mezzio\Csrf\CsrfGuardInterface $guard */
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);

        $rawKeys = $this->webAuthn->findByUserId($currentUser->id);

        // Encode credential IDs as base64url for safe URL embedding.
        $keys = array_map(static function (array $key): array {
            $key['credential_id_url'] = rtrim(
                strtr(base64_encode($key['credential_id']), '+/', '-_'),
                '=',
            );
            return $key;
        }, $rawKeys);

        $flash = $request->getQueryParams();

        return new HtmlResponse(
            $this->renderer->render('app::profile/webauthn', [
                'user'      => $currentUser,
                'csrfToken' => $guard->generateToken(),
                'active'    => 'profile',
                'keys'      => $keys,
                'success'   => $flash['success'] ?? null,
                'error'     => $flash['error']   ?? null,
            ])
        );
    }
}

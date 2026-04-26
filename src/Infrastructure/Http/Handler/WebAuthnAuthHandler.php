<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Mezzio\Session\SessionInterface;
use Mezzio\Template\TemplateRendererInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Application\Repository\WebAuthnCredentialRepositoryInterface;
use TowerDNS\Application\Services\WebAuthnService;

/**
 * GET /login/webauthn
 *
 * Renders the WebAuthn second-factor authentication page.
 * Generates assertion options and stores the challenge in the session.
 * Requires session[mfa_pending] to be set by LoginHandler.
 */
final class WebAuthnAuthHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly TemplateRendererInterface              $renderer,
        private readonly WebAuthnService                       $webAuthn,
        private readonly WebAuthnCredentialRepositoryInterface $credentialRepo,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $session = $request->getAttribute(SessionInterface::class);
        assert($session instanceof SessionInterface);

        $userId = $session->get('mfa_pending');
        if (!is_string($userId) || $userId === '') {
            return new RedirectResponse('/login');
        }

        $credentials = $this->credentialRepo->findByUserId($userId);
        if ($credentials === []) {
            // No WebAuthn keys — fall back to login.
            return new RedirectResponse('/login');
        }

        $credentialIds = array_column(
            array_map(static fn(array $k) => ['credential_id' => $k['source']->publicKeyCredentialId], $credentials),
            'credential_id',
        );

        $options    = $this->webAuthn->createAuthenticationOptions($credentialIds);
        $optionsJson = $this->webAuthn->serializeRequestOptions($options);

        $session->set('webauthn_auth_options', $optionsJson);

        return new HtmlResponse(
            $this->renderer->render('app::login_webauthn', [
                'optionsJson' => $optionsJson,
                'error'       => null,
            ])
        );
    }
}

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
use TowerDNS\Application\Services\TotpSecretService;
use TowerDNS\Application\Services\WebAuthnService;
use TowerDNS\Infrastructure\Http\SessionSecurity;

/**
 * GET /login/webauthn
 *
 * Renders the WebAuthn second-factor authentication page.
 * Generates assertion options and stores the challenge in the session.
 * Requires session[mfa_pending] to be set by LoginHandler.
 * @psalm-api Constructed through runtime dependency injection or command/handler registration.
 */
final readonly class WebAuthnAuthHandler implements RequestHandlerInterface
{
    public function __construct(
        private TemplateRendererInterface              $renderer,
        private WebAuthnService                       $webAuthn,
        private WebAuthnCredentialRepositoryInterface $credentialRepo,
        private SessionSecurity                        $sessionSecurity,
        private TotpSecretService                     $totpSecrets,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $session = $request->getAttribute(SessionInterface::class);
        assert($session instanceof SessionInterface);

        $userId = $this->sessionSecurity->pendingMfaUserId($session);
        if ($userId === null || !in_array($session->get('mfa_type'), ['webauthn', 'passwordless'], true)) {
            return new RedirectResponse('/login');
        }

        $credentials = $this->credentialRepo->findByUserId($userId);
        if ($credentials === []) {
            // No WebAuthn keys — fall back to login.
            return new RedirectResponse('/login');
        }

        $credentialIds = [];
        $transports    = [];
        foreach ($credentials as $credential) {
            $id              = $credential['source']->publicKeyCredentialId;
            $credentialIds[] = $id;
            $transports[$id] = array_values(array_filter($credential['source']->transports, is_string(...)));
        }

        $options     = $this->webAuthn->createAuthenticationOptions($credentialIds, transportsByCredentialId: $transports);
        $optionsJson = $this->webAuthn->serializeRequestOptions($options);

        $session->set('webauthn_auth_options', $optionsJson);

        return new HtmlResponse(
            $this->renderer->render('app::login_webauthn', [
                'optionsJson'   => $optionsJson,
                'totpAvailable' => $this->totpSecrets->isEnabled($userId),
                'error'         => null,
            ])
        );
    }
}

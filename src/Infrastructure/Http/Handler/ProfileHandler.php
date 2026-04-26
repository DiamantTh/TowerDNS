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
use TowerDNS\Application\Repository\UserRepositoryInterface;
use TowerDNS\Application\Repository\WebAuthnCredentialRepositoryInterface;
use TowerDNS\Domain\Auth\User;

/**
 * GET /profile — Profil-Übersicht.
 *
 * Zeigt den aktuellen Anzeigenamen, TOTP-Status und die Anzahl der
 * registrierten WebAuthn-Keys. Von hier aus gelangt man zu den
 * Unter-Seiten /profile/password, /profile/totp und /profile/webauthn.
 */
final class ProfileHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly TemplateRendererInterface              $renderer,
        private readonly UserRepositoryInterface               $users,
        private readonly WebAuthnCredentialRepositoryInterface $webauthn,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var User $currentUser */
        $currentUser = $request->getAttribute(User::class);

        /** @var CsrfGuardInterface $guard */
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);

        $totpEnabled  = $this->users->fetchTotpSecret($currentUser->id) !== null;
        $webAuthnKeys = $this->webauthn->findByUserId($currentUser->id);

        return new HtmlResponse(
            $this->renderer->render('app::profile/index', [
                'user'         => $currentUser,
                'csrfToken'    => $guard->generateToken(),
                'totpEnabled'  => $totpEnabled,
                'webAuthnKeys' => $webAuthnKeys,
                'active'       => 'profile',
            ])
        );
    }
}

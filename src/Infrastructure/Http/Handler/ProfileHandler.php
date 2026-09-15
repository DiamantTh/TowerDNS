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
use TowerDNS\Application\Repository\UserRepositoryInterface;
use TowerDNS\Application\Repository\WebAuthnCredentialRepositoryInterface;
use TowerDNS\Application\Services\TotpSecretService;
use TowerDNS\Application\Theme\ThemeManager;
use TowerDNS\Domain\Auth\User;

/**
 * GET /profile — Profil-Übersicht.
 *
 * Zeigt den aktuellen Anzeigenamen, TOTP-Status und die Anzahl der
 * registrierten WebAuthn-Keys. Von hier aus gelangt man zu den
 * Unter-Seiten /profile/password, /profile/totp und /profile/webauthn.
 */
final readonly class ProfileHandler implements RequestHandlerInterface
{
    public function __construct(
        private TemplateRendererInterface              $renderer,
        private UserRepositoryInterface               $users,
        private WebAuthnCredentialRepositoryInterface $webauthn,
        private TotpSecretService                     $totpSecrets,
        private ThemeManager                          $themes,
        private TranslatorInterface                   $translator,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var User $currentUser */
        $currentUser = $request->getAttribute(User::class);

        /** @var CsrfGuardInterface $guard */
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);

        // ── POST: Anzeigenamen aktualisieren ──────────────────────────────
        if ($request->getMethod() === 'POST') {
            /** @var array<string, mixed> $body */
            $body  = (array) ($request->getParsedBody() ?? []);
            $raw   = $body['csrf_token'] ?? '';
            $token = is_array($raw) ? (string) ($raw[0] ?? '') : (string) $raw;

            if (!$guard->validateToken($token)) {
                return new RedirectResponse('/profile?error=' . rawurlencode($this->translator->translate('http.error.invalid-request')));
            }

            $displayName = trim((string) ($body['display_name'] ?? ''));
            $theme       = trim((string) ($body['theme'] ?? 'system'));

            if ($theme !== 'system' && !$this->themes->has($theme)) {
                return new RedirectResponse('/profile?error=' . rawurlencode($this->translator->translate('profile.error.invalid-theme')));
            }

            $this->users->updateDisplayName($currentUser->id, $displayName);
            $this->users->updateTheme($currentUser->id, $theme);

            return new RedirectResponse('/profile?success=' . rawurlencode($this->translator->translate('profile.success.updated')));
        }

        // ── GET ───────────────────────────────────────────────────────────
        $error   = $request->getQueryParams()['error']   ?? null;
        $success = $request->getQueryParams()['success'] ?? null;

        $totpEnabled  = $this->totpSecrets->isEnabled($currentUser->id);
        $webAuthnKeys = $this->webauthn->findByUserId($currentUser->id);

        return new HtmlResponse(
            $this->renderer->render('app::profile/index', [
                'user'         => $currentUser,
                'csrfToken'    => $guard->generateToken(),
                'totpEnabled'  => $totpEnabled,
                'webAuthnKeys' => $webAuthnKeys,
                'active'       => 'profile',
                'error'        => $error,
                'success'      => $success,
            ])
        );
    }
}

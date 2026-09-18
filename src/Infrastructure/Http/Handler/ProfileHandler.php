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
use TowerDNS\Application\Repository\AccountRepositoryInterface;
use TowerDNS\Application\Repository\WebAuthnCredentialRepositoryInterface;
use TowerDNS\Application\Services\AuditLogService;
use TowerDNS\Application\Services\ProfileService;
use TowerDNS\Application\Services\SupportedLocales;
use TowerDNS\Application\Services\TotpSecretService;
use TowerDNS\Domain\Account\PersonalAccount;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Infrastructure\Http\ActiveAccountContext;

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
        private WebAuthnCredentialRepositoryInterface $webauthn,
        private TotpSecretService                     $totpSecrets,
        private ProfileService                        $profiles,
        private AccountRepositoryInterface           $accounts,
        private AuditLogService                      $audit,
        private TranslatorInterface                   $translator,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $currentUser = $request->getAttribute('actor_user');
        if (!$currentUser instanceof User) {
            return new HtmlResponse($this->translator->translate('http.error.forbidden'), 403);
        }

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

            try {
                $this->profiles->update(
                    $currentUser,
                    (string) ($body['display_name'] ?? ''),
                    (string) ($body['theme'] ?? ''),
                    (string) ($body['locale'] ?? ''),
                );
                $this->audit->record($request, 'user.profile.updated', 'user', $currentUser->id, $currentUser->id, null, null, null, null, $currentUser->id, null, null, ['fields' => ['display_name', 'theme', 'locale']]);
            } catch (\InvalidArgumentException) {
                return new RedirectResponse('/profile?error=' . rawurlencode($this->translator->translate('profile.error.invalid-preference')));
            } catch (\Throwable) {
                return new RedirectResponse('/profile?error=' . rawurlencode($this->translator->translate('http.error.operation-failed')));
            }

            return new RedirectResponse('/profile?success=updated');
        }

        // ── GET ───────────────────────────────────────────────────────────
        $error   = $request->getQueryParams()['error']   ?? null;
        $success = $request->getQueryParams()['success'] ?? null;
        if ($success === 'updated') {
            $success = $this->translator->translate('profile.success.updated');
        }

        $totpEnabled   = $this->totpSecrets->isEnabled($currentUser->id);
        $webAuthnCount = count($this->webauthn->findByUserId($currentUser->id));
        $memberships   = [];
        $personal      = null;
        foreach ($this->accounts->findByUserId($currentUser->id) as $account) {
            $role  = $this->accounts->getEffectiveRole($account->id, $currentUser->id);
            $entry = ['id' => $account->id, 'name' => $account->name, 'kind' => $account->kind()->value, 'role' => $role?->value];
            if ($account->slug === PersonalAccount::slugFor($currentUser->id)) {
                $personal = $entry;
            } else {
                $memberships[] = $entry;
            }
        }
        $active = $request->getAttribute(ActiveAccountContext::class);

        return new HtmlResponse(
            $this->renderer->render('app::profile/index', [
                'user'               => $currentUser,
                'csrfToken'          => $guard->generateToken(),
                'totpEnabled'        => $totpEnabled,
                'webAuthnKeyCount'   => $webAuthnCount,
                'personalAccount'    => $personal,
                'accountMemberships' => $memberships,
                'activeAccountId'    => $active instanceof ActiveAccountContext ? $active->account?->id : null,
                'supportedLocales'   => SupportedLocales::all(),
                'active'             => 'profile',
                'error'              => $error,
                'success'            => $success,
            ])
        );
    }
}

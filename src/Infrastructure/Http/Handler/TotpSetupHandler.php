<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Mezzio\Csrf\CsrfGuardInterface;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Session\SessionInterface;
use Mezzio\Template\TemplateRendererInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Application\Repository\UserRepositoryInterface;
use TowerDNS\Application\Services\TotpService;
use TowerDNS\Domain\Auth\User;

/**
 * GET  /profile/totp — show TOTP setup or disable form.
 * POST /profile/totp — enable or disable TOTP for the authenticated user.
 *
 * Enable flow:
 *   1. GET: generate a pending secret, store in session, show provisioning URI + confirm form.
 *   2. POST action=enable: verify one-time code against pending secret → save to DB.
 *
 * Disable flow:
 *   POST action=disable: verify current TOTP code → clear secret in DB.
 */
final readonly class TotpSetupHandler implements RequestHandlerInterface
{
    private const string SESSION_KEY = 'totp_setup_secret';
    private const string ISSUER      = 'TowerDNS';

    public function __construct(
        private TemplateRendererInterface $renderer,
        private UserRepositoryInterface   $users,
        private TotpService               $totp,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var User $user */
        $user = $request->getAttribute(User::class);

        /** @var CsrfGuardInterface $guard */
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);

        $session = $request->getAttribute(SessionInterface::class);

        if ($request->getMethod() === 'GET') {
            return $this->handleGet($user, $session, $guard);
        }

        return $this->handlePost($user, $session, $guard, $request);
    }

    private function handleGet(User $user, mixed $session, CsrfGuardInterface $guard): ResponseInterface
    {
        $currentSecret = $this->users->fetchTotpSecret($user->id);

        if ($currentSecret !== null) {
            return $this->renderDisableForm($user, null, null, $guard);
        }

        // Not set up yet — reuse or generate a pending secret
        if (!$session instanceof SessionInterface) {
            return new RedirectResponse('/');
        }

        $pendingSecret = $session->has(self::SESSION_KEY)
            ? (string) $session->get(self::SESSION_KEY)
            : '';

        if ($pendingSecret === '') {
            $pendingSecret = $this->totp->generateSecret();
            $session->set(self::SESSION_KEY, $pendingSecret);
        }

        return $this->renderSetupForm($user, $pendingSecret, null, null, $guard);
    }

    private function handlePost(
        User $user,
        mixed $session,
        CsrfGuardInterface $guard,
        ServerRequestInterface $request,
    ): ResponseInterface {
        /** @var array<string, string> $body */
        $body  = (array) ($request->getParsedBody() ?? []);
        $token = (string) ($body['csrf_token'] ?? '');

        if (!$guard->validateToken($token)) {
            return new HtmlResponse(
                $this->renderer->render('app::profile/totp', [
                    'user'            => $user,
                    'totpActive'      => false,
                    'provisioningUri' => null,
                    'secret'          => null,
                    'secretFormatted' => null,
                    'error'           => 'Ungültige Anfrage. Bitte versuche es erneut.',
                    'success'         => null,
                    'csrfToken'       => $guard->generateToken(),
                ]),
                400,
            );
        }

        $action = (string) ($body['action'] ?? '');
        $code   = trim((string) ($body['code'] ?? ''));

        if ($action === 'enable') {
            return $this->enable($user, $session, $guard, $code);
        }

        if ($action === 'disable') {
            return $this->disable($user, $guard, $code);
        }

        return new RedirectResponse('/profile/totp');
    }

    private function enable(
        User $user,
        mixed $session,
        CsrfGuardInterface $guard,
        string $code,
    ): ResponseInterface {
        if (!$session instanceof SessionInterface || !$session->has(self::SESSION_KEY)) {
            // Session expired or missing — restart
            return new RedirectResponse('/profile/totp');
        }

        $pendingSecret = (string) $session->get(self::SESSION_KEY);

        if ($code === '') {
            return $this->renderSetupForm($user, $pendingSecret, 'Bitte gib den Code ein.', null, $guard);
        }

        if (!$this->totp->verify($code, $pendingSecret)) {
            return $this->renderSetupForm(
                $user,
                $pendingSecret,
                'Ungültiger Code. Bitte erneut versuchen.',
                null,
                $guard,
                400,
            );
        }

        $this->users->saveTotpSecret($user->id, $pendingSecret);
        $session->unset(self::SESSION_KEY);

        return $this->renderDisableForm(
            $user,
            null,
            'Zwei-Faktor-Authentifizierung wurde erfolgreich aktiviert.',
            $guard,
        );
    }

    private function disable(
        User $user,
        CsrfGuardInterface $guard,
        string $code,
    ): ResponseInterface {
        $currentSecret = $this->users->fetchTotpSecret($user->id);

        if ($currentSecret === null) {
            return new RedirectResponse('/profile/totp');
        }

        if ($code === '') {
            return $this->renderDisableForm($user, 'Bitte gib den Code ein.', null, $guard);
        }

        if (!$this->totp->verify($code, $currentSecret)) {
            return $this->renderDisableForm($user, 'Ungültiger Code.', null, $guard, 400);
        }

        $this->users->saveTotpSecret($user->id, null);

        return $this->renderSetupForm(
            $user,
            $this->generateFreshSecret(),
            null,
            'Zwei-Faktor-Authentifizierung wurde deaktiviert.',
            $guard,
        );
    }

    private function generateFreshSecret(): string
    {
        // Generate new secret but do NOT store it yet — the user must confirm
        return $this->totp->generateSecret();
    }

    private function renderSetupForm(
        User $user,
        string $secret,
        ?string $error,
        ?string $success,
        CsrfGuardInterface $guard,
        int $status = 200,
    ): HtmlResponse {
        $provisioningUri = $this->totp->getProvisioningUri($secret, $user->email, self::ISSUER);

        return new HtmlResponse(
            $this->renderer->render('app::profile/totp', [
                'user'            => $user,
                'totpActive'      => false,
                'provisioningUri' => $provisioningUri,
                'secret'          => $secret,
                'secretFormatted' => implode(' ', str_split($secret, 4)),
                'error'           => $error,
                'success'         => $success,
                'csrfToken'       => $guard->generateToken(),
            ]),
            $status,
        );
    }

    private function renderDisableForm(
        User $user,
        ?string $error,
        ?string $success,
        CsrfGuardInterface $guard,
        int $status = 200,
    ): HtmlResponse {
        return new HtmlResponse(
            $this->renderer->render('app::profile/totp', [
                'user'            => $user,
                'totpActive'      => true,
                'provisioningUri' => null,
                'secret'          => null,
                'secretFormatted' => null,
                'error'           => $error,
                'success'         => $success,
                'csrfToken'       => $guard->generateToken(),
            ]),
            $status,
        );
    }
}

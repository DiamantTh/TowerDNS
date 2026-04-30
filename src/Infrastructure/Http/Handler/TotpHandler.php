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
use TowerDNS\Application\Services\AuditLogService;
use TowerDNS\Application\Services\TotpService;

/**
 * GET  /login/totp — show TOTP input form.
 * POST /login/totp — verify TOTP code and complete login.
 *
 * Requires session[mfa_pending] to be set by {@see LoginHandler}.
 * If the session does not contain that key the user is redirected back
 * to /login.
 */
final readonly class TotpHandler implements RequestHandlerInterface
{
    public function __construct(
        private TemplateRendererInterface $renderer,
        private UserRepositoryInterface   $users,
        private TotpService               $totp,
        private AuditLogService           $audit,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $session = $request->getAttribute(SessionInterface::class);

        if (!$session instanceof SessionInterface || !$session->has('mfa_pending')) {
            return new RedirectResponse('/login');
        }

        /** @var CsrfGuardInterface $guard */
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);

        if ($request->getMethod() === 'GET') {
            return new HtmlResponse(
                $this->renderer->render('app::mfa_totp', [
                    'error'     => null,
                    'csrfToken' => $guard->generateToken(),
                ])
            );
        }

        // POST — verify TOTP code
        /** @var array<string, string> $body */
        $body  = (array) ($request->getParsedBody() ?? []);
        $token = (string) ($body['csrf_token'] ?? '');

        if (!$guard->validateToken($token)) {
            return new HtmlResponse(
                $this->renderer->render('app::mfa_totp', [
                    'error'     => 'Ungültige Anfrage. Bitte versuche es erneut.',
                    'csrfToken' => $guard->generateToken(),
                ]),
                400
            );
        }

        $code   = trim((string) ($body['code'] ?? ''));
        $userId = (string) $session->get('mfa_pending');

        if ($code === '') {
            return $this->renderError('Bitte gib den Code ein.', $guard);
        }

        $secret = $this->users->fetchTotpSecret($userId);

        if ($secret === null || !$this->totp->verify($code, $secret)) {
            return $this->renderError('Ungültiger Code. Bitte versuche es erneut.', $guard);
        }

        // Code correct — complete login.
        $session->unset('mfa_pending');
        $session->regenerate();
        $session->set('user_id', $userId);
        $this->users->updateLastLoginAt($userId);
        $this->audit->recordLogin($request, $userId);

        return new RedirectResponse('/');
    }

    private function renderError(string $error, CsrfGuardInterface $guard): ResponseInterface
    {
        return new HtmlResponse(
            $this->renderer->render('app::mfa_totp', [
                'error'     => $error,
                'csrfToken' => $guard->generateToken(),
            ]),
            401
        );
    }
}

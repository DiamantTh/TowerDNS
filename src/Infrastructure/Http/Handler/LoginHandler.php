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
use TowerDNS\Application\Repository\UserRepositoryInterface;

/**
 * Handles GET /login (show form) and POST /login (authenticate).
 */
final class LoginHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly TemplateRendererInterface $renderer,
        private readonly UserRepositoryInterface   $users,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $session = $request->getAttribute(SessionInterface::class);

        if ($request->getMethod() === 'GET') {
            return new HtmlResponse(
                $this->renderer->render('app::login', ['error' => null])
            );
        }

        // POST — authenticate
        /** @var array<string, string> $body */
        $body  = (array) ($request->getParsedBody() ?? []);
        $email = trim((string) ($body['email'] ?? ''));
        $pass  = (string) ($body['password'] ?? '');

        $error = $this->authenticate($email, $pass, $session);

        if ($error !== null) {
            return new HtmlResponse(
                $this->renderer->render('app::login', ['error' => $error]),
                401
            );
        }

        return new RedirectResponse('/');
    }

    private function authenticate(
        string $email,
        string $password,
        mixed  $session,
    ): ?string {
        if ($email === '' || $password === '') {
            return 'E-Mail und Passwort sind erforderlich.';
        }

        $user = $this->users->findByEmail($email);
        if ($user === null) {
            return 'Ungültige Anmeldedaten.';
        }

        $hash = $this->users->fetchPasswordHash($email);
        if ($hash === null || !password_verify($password, $hash)) {
            return 'Ungültige Anmeldedaten.';
        }

        if (!$session instanceof SessionInterface) {
            return 'Session nicht verfügbar.';
        }

        $session->regenerate();
        $session->set('user_id', $user->id);

        return null;
    }
}

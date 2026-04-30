<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Mezzio\Csrf\CsrfGuardInterface;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Template\TemplateRendererInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Application\Repository\PasswordResetTokenRepositoryInterface;
use TowerDNS\Application\Repository\UserRepositoryInterface;
use TowerDNS\Application\Services\MailService;

/**
 * Password-forgot flow.
 *
 * GET  /password/forgot  — show request form
 * POST /password/forgot  — send reset link (if address is known)
 *
 * The response is identical regardless of whether the address exists in the
 * database to prevent user-enumeration attacks.
 */
final readonly class ForgotPasswordHandler implements RequestHandlerInterface
{
    private const int TOKEN_TTL_SECONDS = 3600; // 1 hour

    public function __construct(
        private TemplateRendererInterface           $renderer,
        private UserRepositoryInterface             $users,
        private PasswordResetTokenRepositoryInterface $tokens,
        private MailService                         $mail,
        private string                              $appBaseUrl,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var CsrfGuardInterface $guard */
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);

        if ($request->getMethod() === 'GET') {
            return new HtmlResponse(
                $this->renderer->render('app::forgot_password', [
                    'csrfToken' => $guard->generateToken(),
                    'sent'      => false,
                ])
            );
        }

        // POST
        /** @var array<string, string> $body */
        $body  = (array) ($request->getParsedBody() ?? []);
        $token = (string) ($body['csrf_token'] ?? '');

        if (!$guard->validateToken($token)) {
            return new HtmlResponse(
                $this->renderer->render('app::forgot_password', [
                    'csrfToken' => $guard->generateToken(),
                    'sent'      => false,
                    'error'     => 'Ungültige Anfrage.',
                ]),
                400
            );
        }

        $email = strtolower(trim((string) ($body['email'] ?? '')));

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            $user = $this->users->findByEmail($email);

            if ($user !== null) {
                $rawToken  = bin2hex(random_bytes(32)); // 64-char hex string
                $tokenHash = hash('sha256', $rawToken);
                $expiresAt = (new \DateTimeImmutable())
                    ->modify('+' . self::TOKEN_TTL_SECONDS . ' seconds')
                    ->format('Y-m-d H:i:s');

                $this->tokens->create($user->id, $tokenHash, $expiresAt);

                $resetLink = rtrim($this->appBaseUrl, '/') . '/password/reset?token=' . rawurlencode($rawToken);
                $this->mail->send(
                    $user->email,
                    'Passwort zurücksetzen — TowerDNS',
                    '<p>Klicke auf den folgenden Link, um dein Passwort zurückzusetzen (gültig 1 Stunde):</p>'
                    . '<p><a href="' . htmlspecialchars($resetLink, ENT_QUOTES) . '">' . htmlspecialchars($resetLink, ENT_QUOTES) . '</a></p>'
                    . '<p>Falls du diese E-Mail nicht angefordert hast, kannst du sie ignorieren.</p>',
                    "Klicke auf den folgenden Link, um dein Passwort zurückzusetzen (gültig 1 Stunde):\n\n"
                    . $resetLink . "\n\nFalls du diese E-Mail nicht angefordert hast, kannst du sie ignorieren.",
                );
            }
            // No else branch — identical response regardless of whether user exists.
        }

        // Always redirect to the same "sent" page to prevent enumeration.
        return new RedirectResponse('/password/forgot?sent=1');
    }
}

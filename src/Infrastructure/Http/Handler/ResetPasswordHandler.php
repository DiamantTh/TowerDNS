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
use TowerDNS\Application\Services\AuditLogService;
use TowerDNS\Application\Services\PasswordPolicy;

/**
 * Password-reset completion handler.
 *
 * GET  /password/reset?token=<raw>  — show new-password form
 * POST /password/reset              — validate token + update password
 */
final readonly class ResetPasswordHandler implements RequestHandlerInterface
{
    public function __construct(
        private TemplateRendererInterface             $renderer,
        private UserRepositoryInterface               $users,
        private PasswordResetTokenRepositoryInterface $tokens,
        private PasswordPolicy                        $policy,
        private AuditLogService                       $audit,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $request->getMethod() === 'POST'
            ? $this->handlePost($request)
            : $this->handleGet($request);
    }

    // ── GET ───────────────────────────────────────────────────────────────────

    private function handleGet(ServerRequestInterface $request): ResponseInterface
    {
        /** @var CsrfGuardInterface $guard */
        $guard    = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $rawToken = trim((string) ($request->getQueryParams()['token'] ?? ''));

        if ($rawToken === '' || !$this->tokenIsValid($rawToken)) {
            return new HtmlResponse(
                $this->renderer->render('app::reset_password', [
                    'csrfToken' => $guard->generateToken(),
                    'token'     => '',
                    'error'     => 'Der Reset-Link ist ungültig oder abgelaufen.',
                ]),
                400
            );
        }

        return new HtmlResponse(
            $this->renderer->render('app::reset_password', [
                'csrfToken' => $guard->generateToken(),
                'token'     => $rawToken,
                'error'     => null,
            ])
        );
    }

    // ── POST ──────────────────────────────────────────────────────────────────

    private function handlePost(ServerRequestInterface $request): ResponseInterface
    {
        /** @var CsrfGuardInterface $guard */
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        /** @var array<string, string> $body */
        $body  = (array) ($request->getParsedBody() ?? []);
        $token = (string) ($body['csrf_token'] ?? '');

        if (!$guard->validateToken($token)) {
            return new HtmlResponse(
                $this->renderer->render('app::reset_password', [
                    'csrfToken' => $guard->generateToken(),
                    'token'     => (string) ($body['reset_token'] ?? ''),
                    'error'     => 'Ungültige Anfrage.',
                ]),
                400
            );
        }

        $rawToken = trim((string) ($body['reset_token'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $confirm  = (string) ($body['password_confirm'] ?? '');

        $renderError = (fn(string $msg): HtmlResponse => new HtmlResponse(
            $this->renderer->render('app::reset_password', [
                'csrfToken' => $guard->generateToken(),
                'token'     => $rawToken,
                'error'     => $msg,
            ]),
            422
        ));

        if ($password !== $confirm) {
            return $renderError('Die Passwörter stimmen nicht überein.');
        }

        try {
            $this->policy->assertValid($password);
        } catch (\InvalidArgumentException $e) {
            return $renderError($e->getMessage());
        }

        $tokenHash = hash('sha256', $rawToken);
        $record    = $this->tokens->findByHash($tokenHash);

        if (!$record instanceof \TowerDNS\Domain\Auth\PasswordResetToken || !$record->isValid()) {
            return $renderError('Der Reset-Link ist ungültig oder abgelaufen.');
        }

        $user = $this->users->findById($record->userId);
        if (!$user instanceof \TowerDNS\Domain\Auth\User) {
            return $renderError('Benutzer nicht gefunden.');
        }

        $newHash = password_hash($password, PASSWORD_ARGON2ID);

        $this->users->updatePasswordHash($record->userId, $newHash);
        $this->tokens->markUsed($record->id, new \DateTimeImmutable()->format('Y-m-d H:i:s'));
        $this->audit->recordPasswordReset($request, $record->userId);

        return new RedirectResponse('/login?reset=1');
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    private function tokenIsValid(string $rawToken): bool
    {
        $record = $this->tokens->findByHash(hash('sha256', $rawToken));
        return $record instanceof \TowerDNS\Domain\Auth\PasswordResetToken && $record->isValid();
    }
}

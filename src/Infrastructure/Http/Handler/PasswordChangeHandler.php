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
use TowerDNS\Application\Repository\UserRepositoryInterface;
use TowerDNS\Application\Services\PasswordPolicy;
use TowerDNS\Domain\Auth\User;

/**
 * GET+POST /profile/password — change the currently logged-in user's password.
 */
final readonly class PasswordChangeHandler implements RequestHandlerInterface
{
    public function __construct(
        private TemplateRendererInterface $renderer,
        private UserRepositoryInterface   $users,
        private PasswordPolicy            $policy,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var User $user */
        $user = $request->getAttribute(User::class);

        /** @var CsrfGuardInterface $guard */
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);

        if ($request->getMethod() === 'GET') {
            $success = isset($request->getQueryParams()['success'])
                ? 'Passwort wurde erfolgreich geändert.'
                : null;

            return $this->renderForm($user, $guard->generateToken(), null, $success);
        }

        return $this->handlePost($user, $guard, $request);
    }

    private function handlePost(User $user, CsrfGuardInterface $guard, ServerRequestInterface $request): ResponseInterface
    {
        /** @var array<string, mixed> $body */
        $body = (array) $request->getParsedBody();

        $raw   = $body['csrf_token'] ?? '';
        $token = is_array($raw) ? (string) ($raw[0] ?? '') : (string) $raw;

        if (!$guard->validateToken($token)) {
            return $this->renderForm($user, $guard->generateToken(), 'Ungültiger CSRF-Token.');
        }

        $currentPassword = is_string($body['current_password'] ?? null) ? $body['current_password'] : '';
        $newPassword     = is_string($body['new_password'] ?? null) ? $body['new_password'] : '';
        $confirmPassword = is_string($body['confirm_password'] ?? null) ? $body['confirm_password'] : '';

        // Verify current password
        $currentHash = $this->users->fetchPasswordHash($user->email);
        if ($currentHash === null || !password_verify($currentPassword, $currentHash)) {
            return $this->renderForm($user, $guard->generateToken(), 'Das aktuelle Passwort ist nicht korrekt.');
        }

        // Check confirmation match
        if ($newPassword !== $confirmPassword) {
            return $this->renderForm($user, $guard->generateToken(), 'Das neue Passwort und die Bestätigung stimmen nicht überein.');
        }

        // Password policy
        try {
            $this->policy->assertValid($newPassword);
        } catch (\InvalidArgumentException $e) {
            return $this->renderForm($user, $guard->generateToken(), $e->getMessage());
        }

        $hash = password_hash(
            $newPassword,
            PASSWORD_ARGON2ID,
            ['memory_cost' => 131072, 'time_cost' => 4, 'threads' => 4],
        );

        $this->users->updatePasswordHash($user->id, $hash);

        return new RedirectResponse('/profile/password?success=1');
    }

    private function renderForm(User $user, string $csrfToken, ?string $error = null, ?string $success = null): ResponseInterface
    {
        return new HtmlResponse(
            $this->renderer->render('app::profile/password', [
                'user'      => $user,
                'csrfToken' => $csrfToken,
                'error'     => $error,
                'success'   => $success,
                'active'    => 'profile',
            ]),
        );
    }
}

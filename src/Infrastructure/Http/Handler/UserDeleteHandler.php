<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

use Laminas\Diactoros\Response\RedirectResponse;
use Laminas\I18n\Translator\TranslatorInterface;
use Mezzio\Csrf\CsrfGuardInterface;
use Mezzio\Csrf\CsrfMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TowerDNS\Application\Exception\AuthorizationException;
use TowerDNS\Application\Repository\UserRepositoryInterface;
use TowerDNS\Application\Services\AuthorizationService;
use TowerDNS\Application\Services\IamAdministrationService;
use TowerDNS\Application\Services\UserLifecycleService;
use TowerDNS\Domain\Auth\Permission;
use TowerDNS\Domain\Auth\User;

/**
 * POST /users/{id}/delete — löscht einen Benutzer.
 *
 * Verhindert Selbst-Löschung des angemeldeten Benutzers.
 */
final readonly class UserDeleteHandler implements RequestHandlerInterface
{
    public function __construct(
        private UserRepositoryInterface $users,
        private AuthorizationService    $authz,
        private IamAdministrationService $iam,
        private UserLifecycleService      $lifecycle,
        private TranslatorInterface       $translator,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var User $currentUser */
        $currentUser = $request->getAttribute(User::class);

        /** @var CsrfGuardInterface $guard */
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        /** @var array<string, string> $body */
        $body  = (array) ($request->getParsedBody() ?? []);
        $token = (string) ($body['csrf_token'] ?? '');

        if (!$guard->validateToken($token)) {
            return new RedirectResponse('/users?error=' . rawurlencode($this->t('http.error.invalid-request')));
        }

        try {
            $this->authz->assert($currentUser, Permission::USER_MANAGE);
        } catch (AuthorizationException) {
            return new RedirectResponse('/users?error=' . rawurlencode($this->t('http.error.forbidden')));
        }

        $targetId = (string) $request->getAttribute('id', '');

        if ($targetId === '' || $targetId === $currentUser->id) {
            return new RedirectResponse('/users?error=' . rawurlencode($this->t('users.error.self-delete-not-allowed')));
        }

        $target = $this->users->findById($targetId);
        if (!$target instanceof User) {
            return new RedirectResponse('/users?error=' . rawurlencode($this->t('users.error.not-found')));
        }

        try {
            $this->iam->assertCanDelete($targetId);
            $this->lifecycle->delete($targetId);
        } catch (\Throwable) {
            return new RedirectResponse('/users?error=' . rawurlencode($this->t('users.error.delete-failed')));
        }

        return new RedirectResponse('/users?success=' . rawurlencode($this->t('users.success.deleted', ['email' => $target->email])));
    }

    /** @param array<string, string> $parameters */
    private function t(string $key, array $parameters = []): string
    {
        $replacements = [];
        foreach ($parameters as $name => $value) {
            $replacements['{' . $name . '}'] = $value;
        }

        return strtr($this->translator->translate($key), $replacements);
    }
}

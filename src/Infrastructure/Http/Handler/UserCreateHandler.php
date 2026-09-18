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
use Symfony\Component\Uid\Uuid;
use TowerDNS\Application\Exception\AuthorizationException;
use TowerDNS\Application\Services\AuthorizationService;
use TowerDNS\Application\Services\PasswordPolicy;
use TowerDNS\Application\Services\UserLifecycleService;
use TowerDNS\Application\Validation\UserInputFilter;
use TowerDNS\Domain\Auth\Permission;
use TowerDNS\Domain\Auth\User;

/**
 * POST /users — legt einen neuen Benutzer an.
 */
final readonly class UserCreateHandler implements RequestHandlerInterface
{
    public function __construct(
        private UserLifecycleService $users,
        private AuthorizationService    $authz,
        private PasswordPolicy          $passwordPolicy,
        private TranslatorInterface     $translator,
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

        $email    = trim(strtolower((string) ($body['email'] ?? '')));
        $password = (string) ($body['password'] ?? '');

        $filter = new UserInputFilter();
        $filter->setData(['email' => $email, 'password' => $password]);

        if (!$filter->isValid()) {
            return new RedirectResponse('/users?error=' . rawurlencode($this->t('users.error.invalid-input')));
        }

        /** @var array{email: string, password: string} $values */
        $values   = $filter->getValues();
        $email    = $values['email'];
        $password = $values['password'];

        try {
            $this->passwordPolicy->assertValid($password);
        } catch (\InvalidArgumentException) {
            return new RedirectResponse('/users?error=' . rawurlencode($this->t('auth.error.password-policy')));
        }

        $hash = password_hash($password, PASSWORD_ARGON2ID, [
            'memory_cost' => 131072,
            'time_cost'   => 4,
            'threads'     => 4,
        ]);

        try {
            $this->users->create(Uuid::v4()->toRfc4122(), $email, $hash);
        } catch (\Throwable) {
            return new RedirectResponse('/users?error=' . rawurlencode($this->t('users.error.create-failed')));
        }

        return new RedirectResponse('/users?success=' . rawurlencode($this->t('users.success.created', ['email' => $email])));
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

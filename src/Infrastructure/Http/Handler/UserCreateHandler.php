<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http\Handler;

use Laminas\Diactoros\Response\RedirectResponse;
use Mezzio\Csrf\CsrfGuardInterface;
use Mezzio\Csrf\CsrfMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\Uid\Uuid;
use TowerDNS\Application\Exception\AuthorizationException;
use TowerDNS\Application\Repository\UserRepositoryInterface;
use TowerDNS\Application\Services\AuthorizationService;
use TowerDNS\Application\Services\PasswordPolicy;
use TowerDNS\Domain\Auth\Permission;
use TowerDNS\Domain\Auth\User;

/**
 * POST /users — legt einen neuen Benutzer an.
 */
final readonly class UserCreateHandler implements RequestHandlerInterface
{
    public function __construct(
        private UserRepositoryInterface $users,
        private AuthorizationService    $authz,
        private PasswordPolicy          $passwordPolicy,
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
            return new RedirectResponse('/users?error=' . rawurlencode('Ungültige Anfrage.'));
        }

        try {
            $this->authz->assert($currentUser, Permission::USER_MANAGE);
        } catch (AuthorizationException $e) {
            return new RedirectResponse('/users?error=' . rawurlencode($e->getMessage()));
        }

        $email    = trim(strtolower((string) ($body['email'] ?? '')));
        $password = (string) ($body['password'] ?? '');

        if ($email === '' || $password === '') {
            return new RedirectResponse('/users?error=' . rawurlencode('E-Mail und Passwort sind erforderlich.'));
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return new RedirectResponse('/users?error=' . rawurlencode('Ungültige E-Mail-Adresse.'));
        }

        try {
            $this->passwordPolicy->assertValid($password);
        } catch (\InvalidArgumentException $e) {
            return new RedirectResponse('/users?error=' . rawurlencode($e->getMessage()));
        }

        $hash = password_hash($password, PASSWORD_ARGON2ID, [
            'memory_cost' => 131072,
            'time_cost'   => 4,
            'threads'     => 4,
        ]);

        try {
            $this->users->create(Uuid::v4()->toRfc4122(), $email, $hash);
        } catch (\Throwable $e) {
            return new RedirectResponse('/users?error=' . rawurlencode('Fehler beim Anlegen: ' . $e->getMessage()));
        }

        return new RedirectResponse('/users?success=' . rawurlencode('Benutzer angelegt: ' . $email));
    }
}

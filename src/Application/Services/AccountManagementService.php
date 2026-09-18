<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Services;

use TowerDNS\Application\Repository\AccountRepositoryInterface;
use TowerDNS\Domain\Account\Account;
use TowerDNS\Domain\Account\AccountKind;
use TowerDNS\Domain\Auth\Permission;
use TowerDNS\Domain\Auth\User;

/**
 * Centralizes account CRUD (create / rename / deactivate) so HTTP handlers
 * never mutate {@see AccountRepositoryInterface} directly. Keeps permission
 * checks and repository writes together and transport-neutral for web/CLI.
 */
final readonly class AccountManagementService
{
    public function __construct(
        private AccountRepositoryInterface $accounts,
        private PermissionService $permissions,
    ) {}

    public function create(User $owner, string $name, string $slug): Account
    {
        $name = trim($name);
        $slug = trim($slug);

        if ($name === '' || $slug === '') {
            throw new \DomainException('Name und Slug sind erforderlich.');
        }

        if (!preg_match('/^[a-z0-9\-]{2,64}$/', $slug)) {
            throw new \DomainException('Slug: nur Kleinbuchstaben, Ziffern und Bindestriche (2–64 Zeichen).');
        }
        if (str_starts_with($slug, 'personal-')) {
            throw new \DomainException('The personal account slug namespace is reserved.');
        }

        $accountId = $this->accounts->create(
            name: $name,
            slug: $slug,
            ownerUserId: $owner->id,
            createdAt: new \DateTimeImmutable()->format('Y-m-d H:i:s'),
        );

        $account = $this->accounts->findById($accountId);
        if (!$account instanceof Account) {
            throw new \DomainException('Account konnte nicht angelegt werden.');
        }

        return $account;
    }

    public function rename(User $actor, int $accountId, string $name): void
    {
        $this->permissions->assertCanManageAccount($accountId, $actor);

        $name = trim($name);
        if ($name === '') {
            throw new \DomainException('Name darf nicht leer sein.');
        }

        $account = $this->accounts->findById($accountId);
        if (!$account instanceof Account) {
            throw new \DomainException('Account nicht gefunden.');
        }

        $this->accounts->updateName($accountId, $name);
    }

    public function deactivate(User $actor, int $accountId): void
    {
        $this->permissions->assertAccount($actor, Permission::ACCOUNT_DELETE, $accountId);

        $account = $this->accounts->findById($accountId);
        if (!$account instanceof Account) {
            throw new \DomainException('Account nicht gefunden.');
        }
        if ($account->kind() === AccountKind::PERSONAL) {
            throw new \DomainException('A personal account cannot be deactivated.');
        }

        $this->accounts->deactivate($accountId);
    }
}

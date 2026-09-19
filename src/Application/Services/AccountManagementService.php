<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Services;

use TowerDNS\Application\Repository\AccountRepositoryInterface;
use TowerDNS\Application\Repository\ManagedZoneRepositoryInterface;
use TowerDNS\Application\Repository\ProviderAccountRepositoryInterface;
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
        private ManagedZoneRepositoryInterface $zones,
        private ProviderAccountRepositoryInterface $providers,
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

        if ($account->kind() === AccountKind::PERSONAL) {
            throw new \DomainException('Personal account details are managed through the user profile.');
        }
        $this->accounts->updateName($accountId, $name);
    }

    public function updateOrganizationDetails(User $actor, int $accountId, string $name, ?string $customerNumber, ?string $externalReference): void
    {
        $this->permissions->assertCanManageAccount($accountId, $actor);
        $account = $this->accounts->findById($accountId);
        if (!$account instanceof Account) {
            throw new \DomainException('Account not found.');
        }
        if ($account->kind() === AccountKind::PERSONAL) {
            throw new \DomainException('Personal account details are managed through the user profile.');
        }
        $name              = trim($name);
        $customerNumber    = $this->optional($customerNumber, 64);
        $externalReference = $this->optional($externalReference, 255);
        if ($name === '' || mb_strlen($name) > 255) {
            throw new \DomainException('Organization name is invalid.');
        }
        $this->accounts->updateOrganizationDetails($accountId, $name, $customerNumber, $externalReference);
    }

    public function delete(User $actor, int $accountId): void
    {
        $this->permissions->assertAccount($actor, Permission::ACCOUNT_DELETE, $accountId);
        $account = $this->accounts->findById($accountId);
        if (!$account instanceof Account) {
            throw new \DomainException('Account not found.');
        }
        if ($account->kind() === AccountKind::PERSONAL) {
            throw new \DomainException('Personal accounts cannot be deleted separately.');
        }
        if ($this->zones->findByAccountId($accountId) !== [] || $this->providers->findByAccountId($accountId) !== []) {
            throw new \DomainException('Account still contains resources.');
        }
        $members = $this->accounts->findMemberships($accountId);
        if (count($members) > 1) {
            throw new \DomainException('Remove all other account members before deleting the account.');
        }
        $this->accounts->delete($accountId);
    }

    private function optional(?string $value, int $maximum): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $maximum) {
            throw new \DomainException('Account field is too long.');
        }
        return $value;
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

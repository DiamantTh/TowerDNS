<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Services;

use TowerDNS\Application\Contracts\CredentialEncryptorInterface;
use TowerDNS\Application\Contracts\ProviderCredentialSchemaInterface;
use TowerDNS\Application\DTO\ProviderAccountListing;
use TowerDNS\Application\DTO\ProviderAccountMutationResult;
use TowerDNS\Application\Exception\AuthorizationException;
use TowerDNS\Application\Exception\ProviderAccountException;
use TowerDNS\Application\Repository\AccountRepositoryInterface;
use TowerDNS\Application\Repository\ProviderAccountRepositoryInterface;
use TowerDNS\Domain\Account\Account;
use TowerDNS\Domain\Account\ProviderAccount;
use TowerDNS\Domain\Auth\User;

/**
 * Tenant-scoped provider-account workflow for HTTP, CLI, and future API use.
 * It never returns plaintext credentials and owns scope authorization.
 */
final readonly class ProviderAccountManagementService
{
    public function __construct(
        private AccountRepositoryInterface $accounts,
        private ProviderAccountRepositoryInterface $providerAccounts,
        private PermissionService $permissions,
        private ProviderCredentialSchemaInterface $schemas,
        private CredentialEncryptorInterface $credentials,
        private ?ResourceLimitService $resourceLimits = null,
    ) {}

    /** @throws AuthorizationException|ProviderAccountException */
    public function list(User $actor, int $accountId): ProviderAccountListing
    {
        $account = $this->account($accountId);
        $this->permissions->assertCanManageProviderAccounts($account->id, $actor);

        $types = [];
        foreach ($this->schemas->definitions() as $id => $definition) {
            if ($definition['user_managed']) {
                $types[] = $id;
            }
        }
        sort($types, SORT_STRING);

        return new ProviderAccountListing($account, $this->providerAccounts->findByAccountId($accountId), $types);
    }

    /**
     * @param array<string, mixed> $credentialInput
     * @throws AuthorizationException|ProviderAccountException
     */
    public function create(User $actor, int $accountId, string $providerType, string $name, array $credentialInput): ProviderAccountMutationResult
    {
        $account = $this->account($accountId);
        $this->permissions->assertCanManageProviderAccounts($account->id, $actor);
        $this->resourceLimits?->assertCanCreateProviderAccount($accountId);
        $this->assertUserManagedType($providerType);

        $name = trim($name);
        if ($name === '') {
            throw new ProviderAccountException(ProviderAccountException::NAME_REQUIRED);
        }

        $encrypted = $this->encryptCredentials($providerType, $credentialInput);
        $id        = $this->providerAccounts->create(
            $accountId,
            $providerType,
            $name,
            $encrypted,
            CredentialService::currentVersion(),
            new \DateTimeImmutable()->format('Y-m-d H:i:s'),
        );

        return new ProviderAccountMutationResult($accountId, $id, $providerType, $name);
    }

    /**
     * @param array<string, mixed> $credentialInput
     * @throws AuthorizationException|ProviderAccountException
     */
    public function replaceCredentials(User $actor, int $accountId, int $providerAccountId, array $credentialInput): ProviderAccountMutationResult
    {
        $account = $this->account($accountId);
        $this->permissions->assertCanManageProviderAccounts($account->id, $actor);
        $providerAccount = $this->providerAccount($providerAccountId, $accountId);

        $encrypted = $this->encryptCredentials($providerAccount->providerType, $credentialInput);
        $this->providerAccounts->replaceCredentials($providerAccountId, $accountId, $encrypted, CredentialService::currentVersion());

        return new ProviderAccountMutationResult($accountId, $providerAccountId, $providerAccount->providerType, $providerAccount->name);
    }

    /** @throws AuthorizationException|ProviderAccountException */
    public function deactivate(User $actor, int $accountId, int $providerAccountId): ProviderAccountMutationResult
    {
        $account = $this->account($accountId);
        $this->permissions->assertCanManageProviderAccounts($account->id, $actor);
        $providerAccount = $this->providerAccount($providerAccountId, $accountId);
        $this->providerAccounts->deactivate($providerAccountId, $accountId);

        return new ProviderAccountMutationResult($accountId, $providerAccountId, $providerAccount->providerType, $providerAccount->name);
    }

    private function account(int $accountId): Account
    {
        $account = $this->accounts->findById($accountId);
        if (!$account instanceof Account || !$account->isActive) {
            throw new ProviderAccountException(ProviderAccountException::ACCOUNT_NOT_FOUND);
        }
        return $account;
    }

    private function providerAccount(int $providerAccountId, int $accountId): ProviderAccount
    {
        $providerAccount = $this->providerAccounts->findById($providerAccountId);
        if (!$providerAccount instanceof ProviderAccount || $providerAccount->accountId !== $accountId) {
            throw new ProviderAccountException(ProviderAccountException::PROVIDER_NOT_FOUND);
        }
        return $providerAccount;
    }

    private function assertUserManagedType(string $providerType): void
    {
        $definition = $this->schemas->definitions()[$providerType] ?? null;
        if ($definition === null) {
            throw new ProviderAccountException(ProviderAccountException::PROVIDER_NOT_FOUND);
        }
        if (!$definition['user_managed']) {
            throw new ProviderAccountException(ProviderAccountException::PROVIDER_NOT_USER_MANAGED);
        }
    }

    /** @param array<string, mixed> $input */
    private function encryptCredentials(string $providerType, array $input): string
    {
        $credentials = $this->schemas->credentialsFromInput($providerType, $input);
        if ($credentials === null || !$this->schemas->credentialsComplete($providerType, $credentials)) {
            throw new ProviderAccountException(ProviderAccountException::CREDENTIALS_INCOMPLETE);
        }

        $json = json_encode($credentials, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        try {
            return $this->credentials->encrypt($json);
        } finally {
            $this->credentials->wipe($json);
        }
    }
}

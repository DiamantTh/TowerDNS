<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Services;

use TowerDNS\Application\Contracts\CredentialEncryptorInterface;
use TowerDNS\Application\Contracts\ProviderConnectionVerifierInterface;
use TowerDNS\Application\Contracts\ProviderCredentialSchemaInterface;
use TowerDNS\Application\DTO\ProviderAccountListing;
use TowerDNS\Application\DTO\ProviderAccountMutationResult;
use TowerDNS\Application\DTO\ProviderConnectionVerification;
use TowerDNS\Application\Exception\AuthorizationException;
use TowerDNS\Application\Exception\ProviderAccountException;
use TowerDNS\Application\Repository\AccountRepositoryInterface;
use TowerDNS\Application\Repository\ProviderAccountRepositoryInterface;
use TowerDNS\Application\Validation\ProviderEndpointPolicy;
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
        private ProviderConnectionVerifierInterface $connectionVerifier,
        private ?ResourceLimitService $resourceLimits = null,
    ) {}

    /** @throws AuthorizationException|ProviderAccountException */
    public function list(User $actor, int $accountId): ProviderAccountListing
    {
        $account = $this->account($accountId);
        $this->permissions->assertAccount($actor, \TowerDNS\Domain\Auth\Permission::ACCOUNT_READ, $account->id);
        $canManage = $this->permissions->authorizeAccount($actor, \TowerDNS\Domain\Auth\Permission::PROVIDER_CREDENTIALS_MANAGE, $account->id);

        $types       = [];
        $definitions = [];
        foreach ($this->schemas->definitions() as $id => $definition) {
            if ($definition['user_managed']) {
                if ($canManage) {
                    $types[] = $id;
                }
                foreach ($definition['credentials'] as &$field) {
                    if ($field['secret']) {
                        unset($field['default']);
                    }
                }
                unset($field);
                $definitions[$id] = $definition;
            }
        }
        sort($types, SORT_STRING);

        ksort($definitions, SORT_STRING);

        return new ProviderAccountListing($account, $this->providerAccounts->findByAccountId($accountId), $types, $definitions, $canManage, $this->permissions->authorizeAccount($actor, \TowerDNS\Domain\Auth\Permission::ZONE_LIST, $accountId));
    }

    /**
     * @param array<string, mixed> $credentialInput
     * @throws AuthorizationException|ProviderAccountException
     */
    public function create(User $actor, int $accountId, string $providerType, string $name, array $credentialInput, ?string $requestId = null): ProviderAccountMutationResult
    {
        $account = $this->account($accountId);
        $this->permissions->assertCanManageProviderAccounts($account->id, $actor);
        $this->assertUserManagedType($providerType);

        if ($requestId !== null) {
            if (preg_match('/^[a-f0-9]{64}$/D', $requestId) !== 1) {
                throw new ProviderAccountException(ProviderAccountException::CREDENTIALS_INCOMPLETE);
            }
            $existing = $this->providerAccounts->findByCreationRequestId($accountId, $requestId);
            if ($existing instanceof ProviderAccount) {
                return new ProviderAccountMutationResult($accountId, $existing->id, $existing->providerType, $existing->name, true);
            }
        }

        $this->resourceLimits?->assertCanCreateProviderAccount($accountId);

        $name = trim($name);
        if ($name === '') {
            throw new ProviderAccountException(ProviderAccountException::NAME_REQUIRED);
        }

        $credentialValues = $this->credentialsFromInput($providerType, $credentialInput);
        $verifiedAt       = new \DateTimeImmutable()->format('Y-m-d H:i:s');
        try {
            $verification = $this->connectionVerifier->verify($providerType, $credentialValues);
            if (!$verification->succeeded()) {
                throw new ProviderAccountException(ProviderAccountException::CONNECTION_VERIFICATION_FAILED, $verification->category, $providerType);
            }
            $encrypted = $this->encryptCredentials($credentialValues);
        } finally {
            $this->wipeCredentials($credentialValues);
        }
        try {
            $id = $this->providerAccounts->create(
                $accountId,
                $providerType,
                $name,
                $encrypted,
                CredentialService::currentVersion(),
                $verifiedAt,
                new \DateTimeImmutable()->format('Y-m-d H:i:s'),
                $requestId,
            );
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $exception) {
            $existing = $requestId !== null ? $this->providerAccounts->findByCreationRequestId($accountId, $requestId) : null;
            if (!$existing instanceof ProviderAccount) {
                throw $exception;
            }
            return new ProviderAccountMutationResult($accountId, $existing->id, $existing->providerType, $existing->name, true);
        }

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

        $credentialValues = $this->credentialsFromInput($providerAccount->providerType, $credentialInput);
        $verifiedAt       = new \DateTimeImmutable()->format('Y-m-d H:i:s');
        try {
            $verification = $this->connectionVerifier->verify($providerAccount->providerType, $credentialValues);
            if (!$verification->succeeded()) {
                throw new ProviderAccountException(ProviderAccountException::CONNECTION_VERIFICATION_FAILED, $verification->category, $providerAccount->providerType);
            }
            $encrypted = $this->encryptCredentials($credentialValues);
        } finally {
            $this->wipeCredentials($credentialValues);
        }
        $this->providerAccounts->replaceCredentials($providerAccountId, $accountId, $encrypted, CredentialService::currentVersion(), $verifiedAt);

        return new ProviderAccountMutationResult($accountId, $providerAccountId, $providerAccount->providerType, $providerAccount->name);
    }

    /** Explicit read-only test of stored credentials; ordinary page reads never call the provider. */
    public function testConnection(User $actor, int $accountId, int $providerAccountId): ProviderConnectionVerification
    {
        $account = $this->account($accountId);
        $this->permissions->assertCanManageProviderAccounts($account->id, $actor);
        $providerAccount = $this->providerAccount($providerAccountId, $accountId);
        if (!$providerAccount->isActive) {
            throw new ProviderAccountException(ProviderAccountException::PROVIDER_NOT_FOUND);
        }

        $json   = $this->credentials->decrypt($providerAccount->credentialsEncrypted);
        $values = [];
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                throw new ProviderAccountException(ProviderAccountException::CREDENTIALS_INCOMPLETE);
            }
            foreach ($decoded as $key => $value) {
                if (!is_string($key) || !is_string($value)) {
                    throw new ProviderAccountException(ProviderAccountException::CREDENTIALS_INCOMPLETE);
                }
                $values[$key] = $value;
            }
            try {
                ProviderEndpointPolicy::assertCredentialsSafe($values);
            } catch (\InvalidArgumentException) {
                throw new ProviderAccountException(ProviderAccountException::INSECURE_ENDPOINT);
            }
            $result = $this->connectionVerifier->verify($providerAccount->providerType, $values);
        } catch (\JsonException) {
            throw new ProviderAccountException(ProviderAccountException::CREDENTIALS_INCOMPLETE);
        } finally {
            $this->wipeCredentials($values);
            $this->credentials->wipe($json);
        }

        $this->providerAccounts->recordVerification(
            $providerAccountId,
            $accountId,
            new \DateTimeImmutable()->format('Y-m-d H:i:s'),
            $result->succeeded() ? null : $result->category,
        );
        return $result;
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

    /** @param array<string, mixed> $input
     *  @return array<string, string>
     */
    private function credentialsFromInput(string $providerType, array $input): array
    {
        $credentials = $this->schemas->credentialsFromInput($providerType, $input);
        if ($credentials === null || !$this->schemas->credentialsComplete($providerType, $credentials)) {
            throw new ProviderAccountException(ProviderAccountException::CREDENTIALS_INCOMPLETE);
        }

        try {
            ProviderEndpointPolicy::assertCredentialsSafe($credentials);
        } catch (\InvalidArgumentException) {
            throw new ProviderAccountException(ProviderAccountException::INSECURE_ENDPOINT);
        }

        return $credentials;
    }

    /** @param array<string, string> $credentials */
    private function encryptCredentials(array $credentials): string
    {
        $json = json_encode($credentials, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        try {
            return $this->credentials->encrypt($json);
        } finally {
            $this->credentials->wipe($json);
        }
    }

    /** @param array<string, string> $credentials */
    private function wipeCredentials(array &$credentials): void
    {
        foreach ($credentials as &$value) {
            $this->credentials->wipe($value);
        }
        unset($value);
    }
}

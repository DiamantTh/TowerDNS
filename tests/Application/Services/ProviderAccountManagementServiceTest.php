<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Application\Services;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TowerDNS\Application\Contracts\CredentialEncryptorInterface;
use TowerDNS\Application\Contracts\ProviderCredentialSchemaInterface;
use TowerDNS\Application\Exception\ProviderAccountException;
use TowerDNS\Application\Repository\AccountRepositoryInterface;
use TowerDNS\Application\Repository\ProviderAccountRepositoryInterface;
use TowerDNS\Application\Repository\ZoneMembershipRepositoryInterface;
use TowerDNS\Application\Services\AuthorizationService;
use TowerDNS\Application\Services\CredentialService;
use TowerDNS\Application\Services\PermissionService;
use TowerDNS\Application\Services\ProviderAccountManagementService;
use TowerDNS\Application\Services\RbacPermissionChecker;
use TowerDNS\Domain\Account\Account;
use TowerDNS\Domain\Account\ProviderAccount;
use TowerDNS\Domain\Account\TeamRole;
use TowerDNS\Domain\Auth\User;

final class ProviderAccountManagementServiceTest extends TestCase
{
    public function testCreateAuthorizesWithinTheAccountAndPersistsEncryptedCredentials(): void
    {
        $accounts  = $this->accounts();
        $providers = $this->createMock(ProviderAccountRepositoryInterface::class);
        $providers->expects(self::once())->method('create')->with(
            42,
            'example',
            'Primary',
            self::callback(static fn(string $blob): bool => $blob !== '' && !str_contains($blob, 'secret-token')),
            CredentialService::currentVersion(),
            self::isType('string'),
        )->willReturn(9);

        $result = $this->service($accounts, $providers)->create(
            new User('member', 'member@example.test'),
            42,
            'example',
            ' Primary ',
            ['token' => 'secret-token'],
        );

        self::assertSame(9, $result->providerAccountId);
        self::assertSame('example', $result->providerType);
    }

    public function testCreateRejectsAProviderThatIsNotUserManaged(): void
    {
        $this->expectException(ProviderAccountException::class);
        $this->expectExceptionMessage(ProviderAccountException::PROVIDER_NOT_USER_MANAGED);

        $this->service($this->accounts(), $this->createMock(ProviderAccountRepositoryInterface::class))->create(
            new User('member', 'member@example.test'),
            42,
            'system-only',
            'Primary',
            ['token' => 'secret-token'],
        );
    }

    public function testListExposesOnlySortedUserManagedProviderTypes(): void
    {
        $providers = $this->createMock(ProviderAccountRepositoryInterface::class);
        $providers->expects(self::once())->method('findByAccountId')->with(42)->willReturn([]);

        $listing = $this->service($this->accounts(), $providers)->list(
            new User('member', 'member@example.test'),
            42,
        );

        self::assertSame(['example', 'second-example'], $listing->allowedTypes);
    }

    public function testReplaceRejectsAProviderAccountFromAnotherTenant(): void
    {
        $providers = $this->createMock(ProviderAccountRepositoryInterface::class);
        $providers->method('findById')->willReturn(new ProviderAccount(9, 99, 'example', 'Other', 'cipher', 3, true, '2026-09-15 00:00:00'));

        $this->expectException(ProviderAccountException::class);
        $this->expectExceptionMessage(ProviderAccountException::PROVIDER_NOT_FOUND);
        $this->service($this->accounts(), $providers)->replaceCredentials(
            new User('member', 'member@example.test'),
            42,
            9,
            ['token' => 'secret-token'],
        );
    }

    /** @return MockObject&AccountRepositoryInterface */
    private function accounts(): AccountRepositoryInterface
    {
        $accounts = $this->createMock(AccountRepositoryInterface::class);
        $accounts->method('findById')->willReturn(new Account(42, 'Example', 'example', 'owner', true, '2026-09-15 00:00:00'));
        $accounts->method('getEffectiveRole')->willReturn(TeamRole::ADMIN);
        return $accounts;
    }

    private function service(AccountRepositoryInterface $accounts, ProviderAccountRepositoryInterface $providers): ProviderAccountManagementService
    {
        $zones       = $this->createMock(ZoneMembershipRepositoryInterface::class);
        $rbac        = new RbacPermissionChecker();
        $permissions = new PermissionService($accounts, $zones, new AuthorizationService($rbac), $rbac);

        return new ProviderAccountManagementService(
            $accounts,
            $providers,
            $permissions,
            new ExampleAccountProviderCredentialSchema(),
            new TestCredentialEncryptor(),
        );
    }
}

final class TestCredentialEncryptor implements CredentialEncryptorInterface
{
    public function encrypt(string $plaintext): string
    {
        return 'cipher:' . hash('sha256', $plaintext);
    }

    public function wipe(string &$plaintext): void
    {
        $plaintext = '';
    }

    public function decrypt(string $ciphertext): string
    {
        throw new \RuntimeException('Not used by this test double.');
    }
}

final class ExampleAccountProviderCredentialSchema implements ProviderCredentialSchemaInterface
{
    public function definitions(): array
    {
        return [
            'example'        => ['label' => 'Example', 'user_managed' => true, 'credentials' => ['token' => ['input' => 'token', 'label' => 'Token', 'required' => true, 'secret' => true]]],
            'second-example' => ['label' => 'Second example', 'user_managed' => true, 'credentials' => ['token' => ['input' => 'token', 'label' => 'Token', 'required' => true, 'secret' => true]]],
            'system-only'    => ['label' => 'System only', 'user_managed' => false, 'credentials' => ['token' => ['input' => 'token', 'label' => 'Token', 'required' => true, 'secret' => true]]],
        ];
    }

    /** @return array<string, string>|null */
    public function credentialsFromInput(string $type, array $input): ?array
    {
        return isset($this->definitions()[$type]) ? ['token' => trim((string) ($input['token'] ?? ''))] : null;
    }

    public function credentialsComplete(string $type, array $credentials): bool
    {
        return isset($this->definitions()[$type]) && ($credentials['token'] ?? '') !== '';
    }
}

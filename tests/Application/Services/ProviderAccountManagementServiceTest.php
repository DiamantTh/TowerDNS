<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Application\Services;

use PHPUnit\Framework\TestCase;
use TowerDNS\Application\Contracts\CredentialEncryptorInterface;
use TowerDNS\Application\Contracts\ProviderConnectionVerifierInterface;
use TowerDNS\Application\Contracts\ProviderCredentialSchemaInterface;
use TowerDNS\Application\DTO\ProviderConnectionVerification;
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

/** @psalm-api Runtime discovery by PHPUnit or local module loading is not statically visible. */
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
            self::callback('is_string'),
            self::callback('is_string'),
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

    public function testCreateDoesNotPersistCredentialsWhenProviderVerificationFails(): void
    {
        $providers = $this->createMock(ProviderAccountRepositoryInterface::class);
        $providers->expects(self::never())->method('create');
        $verifier = $this->createMock(ProviderConnectionVerifierInterface::class);
        $verifier->expects(self::once())->method('verify')->with('example', ['token' => 'bad-token'])
            ->willReturn(ProviderConnectionVerification::failure(ProviderConnectionVerification::AUTHENTICATION_FAILED));

        try {
            $this->service($this->accounts(), $providers, $verifier)->create(
                new User('member', 'member@example.test'),
                42,
                'example',
                'Primary',
                ['token' => 'bad-token'],
            );
            self::fail('Unverified credentials must not be stored.');
        } catch (ProviderAccountException $exception) {
            self::assertSame(ProviderAccountException::CONNECTION_VERIFICATION_FAILED, $exception->reason);
            self::assertSame(ProviderConnectionVerification::AUTHENTICATION_FAILED, $exception->verificationCategory);
            self::assertStringNotContainsString('bad-token', $exception->getMessage());
        }
    }

    public function testCreateRequestReplayReturnsExistingConnectionWithoutReverification(): void
    {
        $providers = $this->createMock(ProviderAccountRepositoryInterface::class);
        $providers->expects(self::once())->method('findByCreationRequestId')->with(42, str_repeat('a', 64))
            ->willReturn(new ProviderAccount(81, 42, 'example', 'Primary', 'ciphertext', 3, true, '2026-09-15 00:00:00'));
        $providers->expects(self::never())->method('create');
        $verifier = $this->createMock(ProviderConnectionVerifierInterface::class);
        $verifier->expects(self::never())->method('verify');

        $result = $this->service($this->accounts(), $providers, $verifier)->create(
            new User('member', 'member@example.test'),
            42,
            'example',
            'Primary',
            ['token' => 'must-not-be-reused'],
            str_repeat('a', 64),
        );
        self::assertSame(81, $result->providerAccountId);
        self::assertTrue($result->idempotentReplay);
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
        self::assertSame(['example', 'second-example'], array_keys($listing->providerDefinitions));
        self::assertSame('Example', $listing->providerDefinitions['example']['label']);
        self::assertSame('token', $listing->providerDefinitions['example']['credentials']['token']['input']);
        self::assertArrayNotHasKey('system-only', $listing->providerDefinitions);
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

    public function testBlankReplacementCredentialDoesNotOverwriteStoredCredentials(): void
    {
        $providers = $this->createMock(ProviderAccountRepositoryInterface::class);
        $providers->method('findById')->willReturn(new ProviderAccount(9, 42, 'example', 'Primary', 'existing-ciphertext', 3, true, '2026-09-15 00:00:00'));
        $providers->expects(self::never())->method('replaceCredentials');

        $this->expectException(ProviderAccountException::class);
        $this->expectExceptionMessage(ProviderAccountException::CREDENTIALS_INCOMPLETE);
        $this->service($this->accounts(), $providers)->replaceCredentials(
            new User('member', 'member@example.test'),
            42,
            9,
            ['token' => ''],
        );
    }

    public function testFailedCredentialReplacementKeepsTheExistingCiphertext(): void
    {
        $providers = $this->createMock(ProviderAccountRepositoryInterface::class);
        $providers->method('findById')->willReturn(new ProviderAccount(9, 42, 'example', 'Primary', 'old-ciphertext', 3, true, '2026-09-15 00:00:00'));
        $providers->expects(self::never())->method('replaceCredentials');
        $verifier = $this->createMock(ProviderConnectionVerifierInterface::class);
        $verifier->method('verify')->willReturn(ProviderConnectionVerification::failure(ProviderConnectionVerification::PERMISSION_DENIED));

        try {
            $this->service($this->accounts(), $providers, $verifier)->replaceCredentials(
                new User('member', 'member@example.test'),
                42,
                9,
                ['token' => 'new-token'],
            );
            self::fail('Failed verification must leave stored credentials untouched.');
        } catch (ProviderAccountException $exception) {
            self::assertSame(ProviderConnectionVerification::PERMISSION_DENIED, $exception->verificationCategory);
        }
    }

    public function testManualConnectionTestIsPermissionScopedAndPersistsOnlyTheCategory(): void
    {
        $providers = $this->createMock(ProviderAccountRepositoryInterface::class);
        $providers->method('findById')->willReturn(new ProviderAccount(9, 42, 'example', 'Primary', 'ciphertext', 3, true, '2026-09-15 00:00:00'));
        $providers->expects(self::once())->method('recordVerification')->with(
            9,
            42,
            self::callback('is_string'),
            ProviderConnectionVerification::AUTHENTICATION_FAILED,
        );
        $verifier = $this->createMock(ProviderConnectionVerifierInterface::class);
        $verifier->expects(self::once())->method('verify')->with('example', ['token' => 'stored-secret'])
            ->willReturn(ProviderConnectionVerification::failure(ProviderConnectionVerification::AUTHENTICATION_FAILED, 401));
        $result = $this->service($this->accounts(), $providers, $verifier)->testConnection(new User('member', 'member@example.test'), 42, 9);
        self::assertSame(ProviderConnectionVerification::AUTHENTICATION_FAILED, $result->category);
        self::assertSame(401, $result->httpStatus);
    }

    public function testManualConnectionTestRejectsAnotherAccountsConnection(): void
    {
        $providers = $this->createMock(ProviderAccountRepositoryInterface::class);
        $providers->method('findById')->willReturn(new ProviderAccount(9, 99, 'example', 'Other', 'ciphertext', 3, true, '2026-09-15 00:00:00'));
        $verifier = $this->createMock(ProviderConnectionVerifierInterface::class);
        $verifier->expects(self::never())->method('verify');

        $this->expectException(ProviderAccountException::class);
        $this->expectExceptionMessage(ProviderAccountException::PROVIDER_NOT_FOUND);
        $this->service($this->accounts(), $providers, $verifier)->testConnection(new User('member', 'member@example.test'), 42, 9);
    }

    public function testViewerCanReadConnectionsWithoutCredentialWriteActions(): void
    {
        $providers = $this->createMock(ProviderAccountRepositoryInterface::class);
        $providers->method('findByAccountId')->with(42)->willReturn([new ProviderAccount(9, 42, 'example', 'Primary', 'encrypted', 3, true, '2026-01-01')]);
        $listing = $this->service($this->accounts(TeamRole::VIEWER), $providers)->list(new User('member', 'member@example.test'), 42);
        self::assertCount(1, $listing->providerAccounts);
        self::assertFalse($listing->canManageCredentials);
        self::assertTrue($listing->canListZones);
        self::assertSame([], $listing->allowedTypes);
        self::assertArrayNotHasKey('default', $listing->providerDefinitions['example']['credentials']['token']);
    }

    public function testViewerCannotMutateProviderCredentials(): void
    {
        $providers = $this->createMock(ProviderAccountRepositoryInterface::class);
        $providers->expects(self::never())->method('create');
        $providers->expects(self::never())->method('replaceCredentials');
        $providers->expects(self::never())->method('deactivate');
        $service = $this->service($this->accounts(TeamRole::VIEWER), $providers);
        $user    = new User('member', 'member@example.test');
        foreach (['create', 'replace', 'deactivate', 'test'] as $action) {
            try {
                match ($action) {
                    'create'  => $service->create($user, 42, 'example', 'Primary', ['token' => 'secret']),
                    'replace' => $service->replaceCredentials($user, 42, 9, ['token' => 'secret']),
                    'test'    => $service->testConnection($user, 42, 9),
                    default   => $service->deactivate($user, 42, 9),
                };
                self::fail('Viewer mutation must be denied.');
            } catch (\TowerDNS\Application\Exception\AuthorizationException $error) {
                self::assertStringContainsString(\TowerDNS\Domain\Auth\Permission::PROVIDER_CREDENTIALS_MANAGE->value, $error->getMessage());
            }
        }
    }

    private function accounts(TeamRole $role = TeamRole::ADMIN): AccountRepositoryInterface
    {
        $accounts = $this->createMock(AccountRepositoryInterface::class);
        $accounts->method('findById')->willReturn(new Account(42, 'Example', 'example', 'owner', true, '2026-09-15 00:00:00'));
        $accounts->method('getEffectiveRole')->willReturn($role);
        return $accounts;
    }

    private function service(
        AccountRepositoryInterface $accounts,
        ProviderAccountRepositoryInterface $providers,
        ?ProviderConnectionVerifierInterface $verifier = null,
    ): ProviderAccountManagementService {
        $zones       = $this->createMock(ZoneMembershipRepositoryInterface::class);
        $rbac        = new RbacPermissionChecker();
        $permissions = new PermissionService($accounts, $zones, new AuthorizationService($rbac), $rbac);

        return new ProviderAccountManagementService(
            $accounts,
            $providers,
            $permissions,
            new ExampleAccountProviderCredentialSchema(),
            new TestCredentialEncryptor(),
            $verifier ?? new TestProviderConnectionVerifier(),
        );
    }
}

final class TestProviderConnectionVerifier implements ProviderConnectionVerifierInterface
{
    #[\Override]
    public function verify(string $providerType, array $credentials): ProviderConnectionVerification
    {
        return ProviderConnectionVerification::success();
    }
}

final class TestCredentialEncryptor implements CredentialEncryptorInterface
{
    #[\Override]
    public function encrypt(string $plaintext): string
    {
        return 'cipher:' . hash('sha256', $plaintext);
    }

    #[\Override]
    public function wipe(string &$plaintext): void
    {
        $plaintext = '';
    }

    #[\Override]
    public function decrypt(string $ciphertext): string
    {
        return '{"token":"stored-secret"}';
    }
}

final class ExampleAccountProviderCredentialSchema implements ProviderCredentialSchemaInterface
{
    #[\Override]
    public function definitions(): array
    {
        return [
            'example'        => ['label' => 'Example', 'user_managed' => true, 'system_configurable' => true, 'credentials' => ['token' => ['input' => 'token', 'label' => 'Token', 'required' => true, 'secret' => true, 'default' => 'secret-default-fixture']]],
            'second-example' => ['label' => 'Second example', 'user_managed' => true, 'system_configurable' => true, 'credentials' => ['token' => ['input' => 'token', 'label' => 'Token', 'required' => true, 'secret' => true, 'default' => 'secret-default-fixture']]],
            'system-only'    => ['label' => 'System only', 'user_managed' => false, 'system_configurable' => true, 'credentials' => ['token' => ['input' => 'token', 'label' => 'Token', 'required' => true, 'secret' => true, 'default' => 'secret-default-fixture']]],
        ];
    }

    /** @return array<string, string>|null */
    #[\Override]
    public function credentialsFromInput(string $type, array $input): ?array
    {
        return isset($this->definitions()[$type]) ? ['token' => trim((string) ($input['token'] ?? ''))] : null;
    }

    #[\Override]
    public function credentialsComplete(string $type, array $credentials): bool
    {
        return isset($this->definitions()[$type]) && ($credentials['token'] ?? '') !== '';
    }
}

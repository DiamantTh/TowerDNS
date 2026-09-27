<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace TowerDNS\Tests\Application\Services;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TowerDNS\Application\DTO\AuditContext;
use TowerDNS\Application\DTO\StepUpAction;
use TowerDNS\Application\Exception\AuthorizationException;
use TowerDNS\Application\Exception\StepUpRequiredException;
use TowerDNS\Application\Repository\AuditLogRepositoryInterface;
use TowerDNS\Application\Repository\StepUpProofNonceRepositoryInterface;
use TowerDNS\Application\Repository\UserRepositoryInterface;
use TowerDNS\Application\Services\AuditLogService;
use TowerDNS\Application\Services\AuthorizationService;
use TowerDNS\Application\Services\PasswordAdministrationService;
use TowerDNS\Application\Services\PasswordPolicy;
use TowerDNS\Application\Services\StepUpProofService;
use TowerDNS\Domain\Account\AuditLogEntry;
use TowerDNS\Domain\Auth\Permission;
use TowerDNS\Domain\Auth\PermissionRegistry;
use TowerDNS\Domain\Auth\Role;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Infrastructure\Clock\SystemClock;

final class PasswordAdministrationServiceTest extends TestCase
{
    public function testAdministrativePasswordResetRequiresActionBoundStepUpBeforeMutation(): void
    {
        $actor             = $this->superadmin('actor');
        $target            = new User('target', 'target@example.test');
        [$service, $users] = $this->service($actor, $target);
        $users->expects(self::never())->method('updatePasswordHash');

        $this->expectException(StepUpRequiredException::class);
        $service->setByAdministrator($actor, 'target', 'a-long-enough-password', false, new AuditContext('actor', 'actor'));
    }

    public function testNonSuperadminCannotResetPasswordOfUserWithHigherPermissions(): void
    {
        $actor = new User('actor', 'actor@example.test', [
            new Role('user-manager', 'User manager', [Permission::USER_MANAGE]),
        ]);
        $target = new User('target', 'target@example.test', [
            new Role('settings-admin', 'Settings admin', [Permission::SYSTEM_SETTINGS_MANAGE]),
        ]);
        [$service, $users] = $this->service($actor, $target);
        $users->expects(self::never())->method('updatePasswordHash');

        $this->expectException(AuthorizationException::class);
        $service->setByAdministrator($actor, 'target', 'a-long-enough-password', false, new AuditContext('actor', 'actor'));
    }

    public function testValidStepUpPermitsResetAndAuditNeverContainsPassword(): void
    {
        $actor                                = $this->superadmin('actor');
        $target                               = new User('target', 'target@example.test');
        $password                             = 'Synthetic-password-42!';
        [$service, $users, $proofs, $entries] = $this->service($actor, $target);
        $users->expects(self::once())->method('updatePasswordHash')->with('target', self::callback(
            static fn(string $hash): bool => password_verify($password, $hash),
        ));
        $users->expects(self::once())->method('invalidateApiKeys')->with('target')->willReturn(2);

        $proof  = $proofs->issue('actor', StepUpAction::IAM_USER_PASSWORD, 'target', null, 'totp');
        $result = $service->setByAdministrator(
            $actor,
            'target',
            $password,
            false,
            new AuditContext('actor', 'actor'),
            $proof,
        );

        self::assertSame(2, $result);
        self::assertCount(1, $entries);
        $entry = $entries[0] ?? null;
        self::assertInstanceOf(AuditLogEntry::class, $entry);
        self::assertSame('iam.user.password.changed', $entry->action);
        self::assertSame(['api_keys_revoked' => 2, 'step_up_method' => 'totp'], $entry->metadataJson);
        self::assertStringNotContainsString($password, json_encode([$entry->beforeJson, $entry->afterJson, $entry->metadataJson], JSON_THROW_ON_ERROR));
    }

    /** @return array{PasswordAdministrationService, UserRepositoryInterface&MockObject, StepUpProofService, \ArrayObject<int, AuditLogEntry>} */
    private function service(User $actor, User $target): array
    {
        /** @var UserRepositoryInterface&MockObject $users */
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findById')->willReturnCallback(static fn(string $id): ?User => $id === $actor->id ? $actor : null);
        $users->method('findByIdForAdministration')->willReturnCallback(static fn(string $id): ?User => $id === $target->id ? $target : null);

        $nonces = $this->createMock(StepUpProofNonceRepositoryInterface::class);
        $nonces->method('claim')->willReturn(true);
        $proofs = new StepUpProofService(str_repeat('n', 32), new SystemClock(), $nonces);

        $entries = new \ArrayObject();
        /** @var AuditLogRepositoryInterface&MockObject $auditRepository */
        $auditRepository = $this->createMock(AuditLogRepositoryInterface::class);
        $auditRepository->method('append')->willReturnCallback(static function (AuditLogEntry $entry) use ($entries): void {
            $entries->append($entry);
        });

        $connection = $this->createMock(Connection::class);
        $connection->method('transactional')->willReturnCallback(static fn(callable $operation): mixed => $operation());

        return [
            new PasswordAdministrationService(
                $connection,
                $users,
                new PasswordPolicy(minLength: 8),
                new AuthorizationService(),
                new PermissionRegistry(),
                $proofs,
                new AuditLogService($auditRepository),
            ),
            $users,
            $proofs,
            $entries,
        ];
    }

    private function superadmin(string $id): User
    {
        return new User($id, $id . '@example.test', [
            new Role('superadmin', 'Superadmin', Permission::cases(), isBuiltIn: true),
        ]);
    }
}

<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Application\Services;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;
use TowerDNS\Application\DTO\AuditContext;
use TowerDNS\Application\Repository\TotpCredentialRepositoryInterface;
use TowerDNS\Application\Repository\WebAuthnCredentialRepositoryInterface;
use TowerDNS\Application\Services\AccountRecoveryService;
use TowerDNS\Application\Services\AuditLogService;
use TowerDNS\Domain\Auth\PasswordResetMethod;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Infrastructure\Persistence\DbalAccountRecoveryRepository;
use TowerDNS\Infrastructure\Persistence\DbalAuditLogRepository;
use TowerDNS\Infrastructure\Persistence\DbalPasswordResetTokenRepository;
use TowerDNS\Infrastructure\Persistence\DbalTransactionRunner;
use TowerDNS\Infrastructure\Persistence\DbalUserRepository;
use TowerDNS\Infrastructure\Persistence\SchemaManager;
use Webauthn\CredentialRecord;
use Webauthn\TrustPath\EmptyTrustPath;

/**
 * @psalm-api Runtime discovery by PHPUnit or local module loading is not statically visible.
 * @psalm-suppress PropertyNotSetInConstructor Test fixtures are installed in setUp().
 */
final class AccountRecoveryServiceTest extends TestCase
{
    private Connection $connection;
    private DbalAccountRecoveryRepository $recoveryRepository;
    private DbalPasswordResetTokenRepository $tickets;
    private DbalUserRepository $users;
    private AuditLogService $audit;
    private string $userId = '11111111-1111-4111-8111-111111111111';

    #[\Override]
    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $schema           = new SchemaManager($this->connection);
        $schema->createTablesIfNotExist();
        $schema->seedSystemRoles();
        $schema->seedFirstUser($this->userId, 'passkey-only@example.test', '');
        $this->recoveryRepository = new DbalAccountRecoveryRepository($this->connection);
        $this->tickets            = new DbalPasswordResetTokenRepository($this->connection);
        $this->users              = new DbalUserRepository($this->connection, $this->clockAt('2026-10-07 12:00:00'));
        $this->audit              = new AuditLogService(new DbalAuditLogRepository($this->connection));
    }

    public function testRecoveryTicketIsHashedOneShotAndExpiresAfterTwentyFourHours(): void
    {
        $webAuthn = $this->createMock(WebAuthnCredentialRepositoryInterface::class);
        $webAuthn->method('findByUserId')->willReturn([]);
        $webAuthn->method('countByUserId')->willReturn(0);
        $totp = $this->createMock(TotpCredentialRepositoryInterface::class);
        $totp->method('findByUserId')->willReturn([]);
        $service = $this->service($webAuthn, $totp, $this->clockAt('2026-10-07 12:00:00'));
        $user    = $this->users->findById($this->userId);
        self::assertInstanceOf(User::class, $user);

        $delivery = $service->authorize($user, $this->userId, new AuditContext($this->userId, $this->userId));
        $record   = $this->tickets->findByHash(hash('sha256', $delivery['raw_ticket']));
        self::assertNotNull($record);
        self::assertSame(PasswordResetMethod::RECOVERY_CODE, $record->method);
        self::assertSame('2026-10-08 12:00:00', $record->expiresAt);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM password_reset_tokens WHERE token_hash = ?', [$delivery['raw_ticket']]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT auth_session_version FROM users WHERE id = ?', [$this->userId]));
        self::assertTrue($this->recoveryRepository->isLocked($this->userId, '2026-10-07 12:00:00'));

        $sessionIdHash = hash('sha256', 'restricted-session-id');
        $beforeExpiry  = $this->service($webAuthn, $totp, $this->clockAt('2026-10-08 11:59:59'));
        self::assertNotNull($beforeExpiry->redeem($delivery['raw_ticket'], $sessionIdHash, new ServerRequest()));
        self::assertNull($beforeExpiry->redeem($delivery['raw_ticket'], $sessionIdHash, new ServerRequest()));
        self::assertTrue($this->tickets->findByHash(hash('sha256', $delivery['raw_ticket']))?->isUsed());
    }

    public function testRecoveryTicketIsInvalidAtTheExactExpiresAtInstant(): void
    {
        $webAuthn = $this->createMock(WebAuthnCredentialRepositoryInterface::class);
        $webAuthn->method('findByUserId')->willReturn([]);
        $webAuthn->method('countByUserId')->willReturn(0);
        $totp = $this->createMock(TotpCredentialRepositoryInterface::class);
        $totp->method('findByUserId')->willReturn([]);
        $user = $this->users->findById($this->userId);
        self::assertInstanceOf(User::class, $user);
        $delivery = $this->service($webAuthn, $totp, $this->clockAt('2026-10-07 12:00:00'))->authorize($user, $this->userId, new AuditContext($this->userId, $this->userId));

        $atExpiry = $this->service($webAuthn, $totp, $this->clockAt('2026-10-08 12:00:00'));
        self::assertNull($atExpiry->redeem($delivery['raw_ticket'], hash('sha256', 'session'), new ServerRequest()));
        self::assertFalse($this->recoveryRepository->isLocked($this->userId, '2026-10-08 12:00:00'));
    }

    public function testRecoverySessionIsBoundAndExpiresAtFifteenMinutesWithoutExtendingOnUse(): void
    {
        $webAuthn = $this->createMock(WebAuthnCredentialRepositoryInterface::class);
        $webAuthn->method('findByUserId')->willReturn([]);
        $webAuthn->method('countByUserId')->willReturn(0);
        $totp = $this->createMock(TotpCredentialRepositoryInterface::class);
        $totp->method('findByUserId')->willReturn([]);
        $user = $this->users->findById($this->userId);
        self::assertInstanceOf(User::class, $user);
        $delivery = $this->service($webAuthn, $totp, $this->clockAt('2026-10-07 12:00:00'))->authorize($user, $this->userId, new AuditContext($this->userId, $this->userId));

        $sessionHash = hash('sha256', 'restricted-session');
        $started     = $this->service($webAuthn, $totp, $this->clockAt('2026-10-07 12:00:00'))->redeem($delivery['raw_ticket'], $sessionHash, new ServerRequest());
        self::assertNotNull($started);
        self::assertSame('2026-10-07 12:15:00', $started['session_expires_at']);
        $beforeExpiry = $this->service($webAuthn, $totp, $this->clockAt('2026-10-07 12:14:59'));
        self::assertTrue($beforeExpiry->validateSession($this->userId, $started['id'], $sessionHash));
        self::assertFalse($beforeExpiry->validateSession('another-user', $started['id'], $sessionHash));
        self::assertFalse($beforeExpiry->validateSession($this->userId, $started['id'], hash('sha256', 'other-session')));

        $atExpiry = $this->service($webAuthn, $totp, $this->clockAt('2026-10-07 12:15:00'));
        self::assertFalse($atExpiry->validateSession($this->userId, $started['id'], $sessionHash));
        self::assertFalse($this->recoveryRepository->isLocked($this->userId, '2026-10-07 12:15:00'));
        self::assertSame('expired', $this->connection->fetchOne('SELECT status FROM account_recoveries WHERE id = ?', [$started['id']]));
    }

    public function testOldCredentialsAreRemovedOnlyAfterNewFido2IsVerifiedAndRecoveryCompletes(): void
    {
        $rows     = [$this->credentialRow('old-key')];
        $webAuthn = $this->createMock(WebAuthnCredentialRepositoryInterface::class);
        $webAuthn->method('findByUserId')->willReturnCallback(static function (string $_userId) use (&$rows): array {
            return $rows;
        });
        $webAuthn->method('countByUserId')->willReturnCallback(static function (string $_userId) use (&$rows): int {
            return count($rows);
        });
        $webAuthn->method('saveDuringRecovery')->willReturnCallback(static function (string $_userId, string $name, CredentialRecord $source, ?string $attachment, int $_configuredMaxCredentials) use (&$rows): void {
            $rows[] = ['credential_id' => $source->publicKeyCredentialId, 'name' => $name, 'created_at' => '2026-10-07 12:00:00', 'last_used_at' => null, 'attachment' => $attachment, 'aaguid' => $source->aaguid->toRfc4122(), 'transports' => [], 'backup_eligible' => false, 'backup_state' => false, 'source' => $source];
        });
        $webAuthn->expects(self::once())->method('delete')->with('old-key', $this->userId)->willReturnCallback(static function (string $credentialId, string $_userId) use (&$rows): void {
            $rows = array_values(array_filter($rows, static fn(array $row): bool => $row['credential_id'] !== $credentialId));
        });

        $totpRows = [['id' => 'totp-old', 'label' => 'Phone', 'created_at' => '2026-01-01 00:00:00', 'last_used_at' => null, 'secret_encrypted' => 'encrypted-secret']];
        $totp     = $this->createMock(TotpCredentialRepositoryInterface::class);
        $totp->method('findByUserId')->willReturnCallback(static function (string $_userId) use (&$totpRows): array {
            return $totpRows;
        });
        $totp->expects(self::once())->method('delete')->with('totp-old', $this->userId)->willReturnCallback(static function (string $id, string $_userId) use (&$totpRows): void {
            $totpRows = array_values(array_filter($totpRows, static fn(array $row): bool => $row['id'] !== $id));
        });

        $clock   = $this->clockAt('2026-10-07 12:00:00');
        $service = $this->service($webAuthn, $totp, $clock);
        $user    = $this->users->findById($this->userId);
        self::assertInstanceOf(User::class, $user);
        $delivery    = $service->authorize($user, $this->userId, new AuditContext($this->userId, $this->userId));
        $sessionHash = hash('sha256', 'recovery-session');
        $started     = $service->redeem($delivery['raw_ticket'], $sessionHash, new ServerRequest());
        self::assertNotNull($started);

        $newKey = $this->credential('new-key');
        $service->persistRecoveryWebAuthn($this->userId, $started['id'], $sessionHash, 'New key', $newKey, 'cross-platform', 10);
        self::assertSame(['old-key', 'new-key'], array_column($rows, 'credential_id'));
        self::assertSame(1, count($totpRows));

        $service->complete($this->userId, $started['id'], $sessionHash, new AuditContext($this->userId, $this->userId));
        self::assertSame(['new-key'], array_column($rows, 'credential_id'));
        self::assertSame([], $totpRows);
        self::assertSame('completed', $this->connection->fetchOne('SELECT status FROM account_recoveries WHERE id = ?', [$started['id']]));
        self::assertNull($this->recoveryRepository->findForSession($started['id'], $this->userId, $sessionHash));
        self::assertFalse($this->recoveryRepository->isLocked($this->userId, '2026-10-07 12:00:01'));
    }

    public function testNewAuthorizationRevokesAnOlderOpenRecoveryTicket(): void
    {
        $rows     = [];
        $webAuthn = $this->createMock(WebAuthnCredentialRepositoryInterface::class);
        $webAuthn->method('findByUserId')->willReturnCallback(static function (string $_userId) use (&$rows): array {
            return $rows;
        });
        $webAuthn->method('countByUserId')->willReturnCallback(static function (string $_userId) use (&$rows): int {
            return count($rows);
        });
        $webAuthn->method('saveDuringRecovery')->willReturnCallback(static function (string $_userId, string $name, CredentialRecord $source, ?string $attachment, int $_ceiling) use (&$rows): void {
            $rows[] = ['credential_id' => $source->publicKeyCredentialId, 'name' => $name, 'created_at' => '2026-10-07 12:00:00', 'last_used_at' => null, 'attachment' => $attachment, 'aaguid' => $source->aaguid->toRfc4122(), 'transports' => [], 'backup_eligible' => false, 'backup_state' => false, 'source' => $source];
        });
        $webAuthn->expects(self::once())->method('delete')->with('superseded-key', $this->userId)->willReturnCallback(static function (string $credentialId, string $_userId) use (&$rows): void {
            $rows = array_values(array_filter($rows, static fn(array $row): bool => $row['credential_id'] !== $credentialId));
        });
        $totp = $this->createMock(TotpCredentialRepositoryInterface::class);
        $totp->method('findByUserId')->willReturn([]);
        $user = $this->users->findById($this->userId);
        self::assertInstanceOf(User::class, $user);
        $service = $this->service($webAuthn, $totp, $this->clockAt('2026-10-07 12:00:00'));

        $first            = $service->authorize($user, $this->userId, new AuditContext($this->userId, $this->userId));
        $firstSessionHash = hash('sha256', 'first-recovery-session');
        $started          = $service->redeem($first['raw_ticket'], $firstSessionHash, new ServerRequest());
        self::assertNotNull($started);
        $service->persistRecoveryWebAuthn($this->userId, $started['id'], $firstSessionHash, 'Temporary key', $this->credential('superseded-key'), null, 10);
        self::assertSame(['superseded-key'], array_column($rows, 'credential_id'));

        $second = $service->authorize($user, $this->userId, new AuditContext($this->userId, $this->userId));

        self::assertTrue($this->tickets->findByHash(hash('sha256', $first['raw_ticket']))?->isUsed());
        self::assertSame([], $rows);
        self::assertSame('revoked', $this->connection->fetchOne('SELECT status FROM account_recoveries WHERE id = ?', [$started['id']]));
        self::assertNull($service->redeem($first['raw_ticket'], hash('sha256', 'old-session'), new ServerRequest()));
        self::assertNotNull($service->redeem($second['raw_ticket'], hash('sha256', 'new-session'), new ServerRequest()));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT auth_session_version FROM users WHERE id = ?', [$this->userId]));
        $auditRows = $this->connection->fetchAllAssociative('SELECT action, metadata_json FROM audit_logs WHERE target_id = ?', [$this->userId]);
        $actions   = array_column($auditRows, 'action');
        self::assertContains('security.account_recovery.authorization.revoked', $actions);
        self::assertContains('security.account_recovery.webauthn.enrollment.revoked', $actions);
        self::assertStringNotContainsString($first['raw_ticket'], json_encode($auditRows, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString($second['raw_ticket'], json_encode($auditRows, JSON_THROW_ON_ERROR));
    }

    public function testNoNewFido2LeavesPreRecoveryCredentialsUntouchedAndAbortRemovesRecoveryEnrollment(): void
    {
        $rows     = [$this->credentialRow('old-key')];
        $webAuthn = $this->createMock(WebAuthnCredentialRepositoryInterface::class);
        $webAuthn->method('findByUserId')->willReturnCallback(static function (string $_userId) use (&$rows): array {
            return $rows;
        });
        $webAuthn->method('countByUserId')->willReturnCallback(static function (string $_userId) use (&$rows): int {
            return count($rows);
        });
        $webAuthn->method('saveDuringRecovery')->willReturnCallback(static function (string $_userId, string $name, CredentialRecord $source, ?string $attachment, int $_configuredMaxCredentials) use (&$rows): void {
            $rows[] = ['credential_id' => $source->publicKeyCredentialId, 'name' => $name, 'created_at' => '2026-10-07 12:00:00', 'last_used_at' => null, 'attachment' => $attachment, 'aaguid' => $source->aaguid->toRfc4122(), 'transports' => [], 'backup_eligible' => false, 'backup_state' => false, 'source' => $source];
        });
        $webAuthn->expects(self::once())->method('delete')->with('new-key', $this->userId)->willReturnCallback(static function (string $credentialId, string $_userId) use (&$rows): void {
            $rows = array_values(array_filter($rows, static fn(array $row): bool => $row['credential_id'] !== $credentialId));
        });
        $totp = $this->createMock(TotpCredentialRepositoryInterface::class);
        $totp->method('findByUserId')->willReturn([]);
        $service = $this->service($webAuthn, $totp, $this->clockAt('2026-10-07 12:00:00'));
        $user    = $this->users->findById($this->userId);
        self::assertInstanceOf(User::class, $user);
        $delivery    = $service->authorize($user, $this->userId, new AuditContext($this->userId, $this->userId));
        $sessionHash = hash('sha256', 'recovery-session');
        $started     = $service->redeem($delivery['raw_ticket'], $sessionHash, new ServerRequest());
        self::assertNotNull($started);

        try {
            $service->complete($this->userId, $started['id'], $sessionHash, new AuditContext($this->userId, $this->userId));
            self::fail('Recovery must not complete before new FIDO2 enrollment.');
        } catch (\DomainException) {
            self::assertSame(['old-key'], array_column($rows, 'credential_id'));
        }

        $service->persistRecoveryWebAuthn($this->userId, $started['id'], $sessionHash, 'Temporary new key', $this->credential('new-key'), null, 10);
        $service->abort($this->userId, $started['id'], $sessionHash, new AuditContext($this->userId, $this->userId));
        self::assertSame(['old-key'], array_column($rows, 'credential_id'));
        self::assertSame('aborted', $this->connection->fetchOne('SELECT status FROM account_recoveries WHERE id = ?', [$started['id']]));
    }

    public function testSessionExpiryRemovesOnlyIncompleteRecoveryEnrollmentAndKeepsOldFactors(): void
    {
        $rows     = [$this->credentialRow('old-key')];
        $webAuthn = $this->createMock(WebAuthnCredentialRepositoryInterface::class);
        $webAuthn->method('findByUserId')->willReturnCallback(static function (string $_userId) use (&$rows): array {
            return $rows;
        });
        $webAuthn->method('countByUserId')->willReturnCallback(static function (string $_userId) use (&$rows): int {
            return count($rows);
        });
        $webAuthn->method('saveDuringRecovery')->willReturnCallback(static function (string $_userId, string $name, CredentialRecord $source, ?string $attachment, int $_recoveryCeiling) use (&$rows): void {
            $rows[] = ['credential_id' => $source->publicKeyCredentialId, 'name' => $name, 'created_at' => '2026-10-07 12:00:00', 'last_used_at' => null, 'attachment' => $attachment, 'aaguid' => $source->aaguid->toRfc4122(), 'transports' => [], 'backup_eligible' => false, 'backup_state' => false, 'source' => $source];
        });
        $webAuthn->expects(self::once())->method('delete')->with('new-key', $this->userId)->willReturnCallback(static function (string $id, string $_userId) use (&$rows): void {
            $rows = array_values(array_filter($rows, static fn(array $row): bool => $row['credential_id'] !== $id));
        });
        $totpRows = [['id' => 'old-totp', 'label' => 'Authenticator', 'created_at' => '2026-01-01 00:00:00', 'last_used_at' => null, 'secret_encrypted' => 'ciphertext']];
        $totp     = $this->createMock(TotpCredentialRepositoryInterface::class);
        $totp->method('findByUserId')->willReturnCallback(static function (string $_userId) use (&$totpRows): array {
            return $totpRows;
        });
        $totp->expects(self::never())->method('delete');

        $user = $this->users->findById($this->userId);
        self::assertInstanceOf(User::class, $user);
        $service     = $this->service($webAuthn, $totp, $this->clockAt('2026-10-07 12:00:00'));
        $ticket      = $service->authorize($user, $this->userId, new AuditContext($this->userId, $this->userId));
        $sessionHash = hash('sha256', 'expiring-session');
        $started     = $service->redeem($ticket['raw_ticket'], $sessionHash, new ServerRequest());
        self::assertNotNull($started);
        $service->persistRecoveryWebAuthn($this->userId, $started['id'], $sessionHash, 'Replacement', $this->credential('new-key'), 'cross-platform', 10);

        self::assertFalse($this->service($webAuthn, $totp, $this->clockAt('2026-10-07 12:15:00'))->isUserLocked($this->userId));
        self::assertSame(['old-key'], array_column($rows, 'credential_id'));
        self::assertCount(1, $totpRows);
        self::assertSame('expired', $this->connection->fetchOne('SELECT status FROM account_recoveries WHERE id = ?', [$started['id']]));
    }

    private function service(WebAuthnCredentialRepositoryInterface $webAuthn, TotpCredentialRepositoryInterface $totp, ClockInterface $clock): AccountRecoveryService
    {
        return new AccountRecoveryService(
            $this->recoveryRepository,
            $this->tickets,
            $webAuthn,
            $totp,
            new DbalTransactionRunner($this->connection),
            $this->audit,
            $clock,
        );
    }

    private function clockAt(string $timestamp): ClockInterface
    {
        return new readonly class ($timestamp) implements ClockInterface {
            public function __construct(private string $timestamp) {}
            #[\Override]
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable($this->timestamp);
            }
        };
    }

    private function credential(string $id): CredentialRecord
    {
        return new CredentialRecord($id, 'public-key', [], 'none', EmptyTrustPath::create(), Uuid::fromString('00000000-0000-0000-0000-000000000000'), 'public-key', $this->userId, 0);
    }

    /** @return array{credential_id:string,name:string,created_at:string,last_used_at:null,attachment:string,aaguid:string,transports:list<string>,backup_eligible:false,backup_state:false,source:CredentialRecord} */
    private function credentialRow(string $id): array
    {
        return ['credential_id' => $id, 'name' => 'Old key', 'created_at' => '2026-01-01 00:00:00', 'last_used_at' => null, 'attachment' => 'cross-platform', 'aaguid' => '00000000-0000-0000-0000-000000000000', 'transports' => [], 'backup_eligible' => false, 'backup_state' => false, 'source' => $this->credential($id)];
    }
}

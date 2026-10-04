<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Http\Handler;

use Doctrine\DBAL\DriverManager;
use Laminas\Diactoros\ServerRequest;
use Laminas\Translator\TranslatorInterface;
use Mezzio\Csrf\CsrfGuardInterface;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Session\Session;
use Mezzio\Session\SessionInterface;
use Mezzio\Template\TemplateRendererInterface;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;
use TowerDNS\Application\Contracts\CredentialEncryptorInterface;
use TowerDNS\Application\DTO\StepUpAction;
use TowerDNS\Application\Repository\AuditLogRepositoryInterface;
use TowerDNS\Application\Repository\SystemSettingsRepositoryInterface;
use TowerDNS\Application\Repository\WebAuthnCredentialRepositoryInterface;
use TowerDNS\Application\Services\AuditLogService;
use TowerDNS\Application\Services\StepUpProofService;
use TowerDNS\Application\Services\TotpSecretService;
use TowerDNS\Application\Services\TotpService;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Infrastructure\Http\Handler\TotpSetupHandler;
use TowerDNS\Infrastructure\Http\SessionSecurity;
use TowerDNS\Infrastructure\Http\StepUpRequestService;
use TowerDNS\Infrastructure\Persistence\DbalStepUpProofNonceRepository;
use TowerDNS\Infrastructure\Persistence\DbalTotpCredentialRepository;
use TowerDNS\Infrastructure\Persistence\SchemaManager;
use Webauthn\CredentialRecord;
use Webauthn\TrustPath\EmptyTrustPath;

/** @psalm-api Runtime discovery by PHPUnit or local module loading is not statically visible. */
final class TotpCredentialRemovalHandlerTest extends TestCase
{
    public function testThreeToTwoAllowsTOTPOrFidoStepUp(): void
    {
        foreach (['totp', 'webauthn'] as $method) {
            [$response, $count] = $this->remove(
                ['JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP', 'KRSXG5DSNFXGOIDBMJSGCY3QOZUWY4R2', 'MFRGGZDFMZTWQ2LKNNWG23TPOI======'],
                ['fido-one'],
                2,
                $method,
                $method === 'totp' ? 'totp-0' : 'fido-one',
            );

            self::assertSame(200, $response->getStatusCode());
            self::assertSame(2, $count);
        }
    }

    public function testTwoToOneAcceptsAnotherTotpOrFidoAndRejectsSelfConfirmation(): void
    {
        $secrets = ['JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP', 'KRSXG5DSNFXGOIDBMJSGCY3QOZUWY4R2'];
        foreach ([['totp', 'totp-1'], ['webauthn', 'fido-one']] as [$method, $factor]) {
            [$response, $count] = $this->remove($secrets, ['fido-one'], 0, $method, $factor);
            self::assertSame(200, $response->getStatusCode());
            self::assertSame(1, $count);
        }

        [$response, $count] = $this->remove($secrets, [], 0, 'totp', 'totp-0');
        self::assertSame(409, $response->getStatusCode());
        self::assertSame(2, $count);
    }

    public function testLastTotpCanBeRemovedWithFidoButNotWithoutFido(): void
    {
        $secret                = ['JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP'];
        [$allowed, $remaining] = $this->remove($secret, ['fido-one'], 0, 'webauthn', 'fido-one');
        self::assertSame(200, $allowed->getStatusCode());
        self::assertSame(0, $remaining);

        [$blocked, $unchanged] = $this->remove($secret, [], 0, 'totp', 'totp-0');
        self::assertSame(409, $blocked->getStatusCode());
        self::assertSame(1, $unchanged);
    }

    public function testTotpProofCannotRemoveFidoAndImpersonationStillBlocksTotpManagement(): void
    {
        [$response, $remaining] = $this->remove(
            ['JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP', 'KRSXG5DSNFXGOIDBMJSGCY3QOZUWY4R2'],
            ['fido-one'],
            0,
            'totp',
            'totp-1',
            true,
        );
        self::assertSame(403, $response->getStatusCode());
        self::assertSame(2, $remaining);
    }

    /**
     * @param list<non-empty-string> $secrets
     * @param list<string>           $fidoIds
     *
     * @return array{\Psr\Http\Message\ResponseInterface, int}
     */
    private function remove(array $secrets, array $fidoIds, int $targetIndex, string $method, string $proofId, bool $impersonating = false): array
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $schema     = new SchemaManager($connection);
        $schema->createTablesIfNotExist();
        $schema->seedSystemRoles();
        $userId = '33333333-3333-4333-8333-333333333333';
        $schema->seedFirstUser($userId, 'person@example.test', 'password-hash');
        $user  = new User($userId, 'person@example.test');
        $clock = new class implements ClockInterface {
            #[\Override]
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('@1768500000');
            }
        };
        $settings = $this->createMock(SystemSettingsRepositoryInterface::class);
        $settings->method('get')->willReturnCallback(static fn(string $key, mixed $default = null): mixed => $key === 'security.totp.max_credentials_per_user' ? 5 : $default);
        $repository      = new DbalTotpCredentialRepository($connection, $clock);
        $totp            = new TotpService($clock);
        $totpCredentials = new TotpSecretService($repository, $totp, new class implements CredentialEncryptorInterface {
            #[\Override] public function encrypt(string $plaintext): string
            {
                return base64_encode($plaintext);
            }
            #[\Override] public function decrypt(string $ciphertext): string
            {
                $value = base64_decode($ciphertext, true);
                if (!is_string($value)) {
                    throw new \RuntimeException('Invalid ciphertext.');
                } return $value;
            }
            #[\Override] public function wipe(string &$plaintext): void
            {
                $plaintext = '';
            }
        }, $settings);
        $ids = [];
        foreach ($secrets as $index => $secret) {
            $ids[] = $totpCredentials->enable($userId, $secret, 'Authenticator ' . ($index + 1));
        }
        $verifiedCredentialId = str_starts_with($proofId, 'totp-')
            ? ($ids[(int) substr($proofId, 5)] ?? '')
            : $proofId;

        $webAuthn = $this->createMock(WebAuthnCredentialRepositoryInterface::class);
        $webAuthn->method('countByUserId')->with($userId)->willReturn(count($fidoIds));
        $fidoRecords = array_map(fn(string $id): array => $this->fidoRow($id, $userId), $fidoIds);
        $webAuthn->method('findByUserId')->with($userId)->willReturn($fidoRecords);
        $session         = new Session([]);
        $proofs          = new StepUpProofService(str_repeat('k', 32), $clock, new DbalStepUpProofNonceRepository($connection));
        $sessionSecurity = new SessionSecurity($clock);
        $targetId        = $ids[$targetIndex];
        $targetHash      = hash('sha256', $targetId);
        $session         = $sessionSecurity->beginStepUp($session, $userId, StepUpAction::PROFILE_TOTP_DELETE, $targetHash, null);
        $sessionSecurity->completeStepUp($session, $userId, null, $method, $proofs, hash('sha256', $verifiedCredentialId));

        $auditRepository = $this->createMock(AuditLogRepositoryInterface::class);
        $shouldDelete    = !$impersonating && match (true) {
            count($ids) >= 3  => in_array($method, ['totp', 'webauthn'], true),
            count($ids) === 2 => $method === 'webauthn' || ($method === 'totp' && $verifiedCredentialId !== $ids[$targetIndex]),
            count($ids) === 1 => $method === 'webauthn' && $fidoIds !== [],
            default           => false,
        };
        $auditRepository->expects($shouldDelete ? self::once() : self::never())->method('append');

        $renderer = $this->createMock(TemplateRendererInterface::class);
        $renderer->method('render')->willReturn('');
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('translate')->willReturnArgument(0);
        $handler = new TotpSetupHandler(
            $connection,
            $renderer,
            $totpCredentials,
            $totp,
            new AuditLogService($auditRepository),
            $translator,
            new StepUpRequestService($sessionSecurity, $proofs),
            $webAuthn,
        );
        $guard = $this->createMock(CsrfGuardInterface::class);
        $guard->method('validateToken')->with('csrf')->willReturn(true);
        $request = new ServerRequest()
            ->withMethod('POST')
            ->withParsedBody(['csrf_token' => 'csrf', 'action' => 'delete', 'credential_id' => $targetId])
            ->withAttribute(User::class, $user)
            ->withAttribute(SessionInterface::class, $session)
            ->withAttribute(CsrfMiddleware::GUARD_ATTRIBUTE, $guard);
        if ($impersonating) {
            $request = $request->withAttribute('impersonation_session', new \TowerDNS\Domain\Account\AdminImpersonationSession(
                'switch-1',
                'actor-1',
                $userId,
                null,
                'test',
                '2026-01-01 00:00:00',
                '2026-01-01 00:10:00',
            ));
        }

        return [$handler->handle($request), $totpCredentials->count($userId)];
    }

    /** @return array<string, mixed> */
    private function fidoRow(string $id, string $userId): array
    {
        $source = new CredentialRecord($id, 'public-key', [], 'none', EmptyTrustPath::create(), Uuid::fromString('00000000-0000-0000-0000-000000000000'), 'public-key', $userId, 0);
        return [
            'credential_id'   => $id, 'name' => 'Key', 'created_at' => '2026-01-01 00:00:00', 'last_used_at' => null,
            'attachment'      => 'cross-platform', 'aaguid' => '00000000-0000-0000-0000-000000000000', 'transports' => [],
            'backup_eligible' => false, 'backup_state' => false, 'source' => $source,
        ];
    }
}

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
use Mezzio\Router\Route;
use Mezzio\Router\RouteResult;
use Mezzio\Session\Session;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\Uid\Uuid;
use TowerDNS\Application\DTO\StepUpAction;
use TowerDNS\Application\Repository\AuditLogRepositoryInterface;
use TowerDNS\Application\Repository\WebAuthnCredentialRepositoryInterface;
use TowerDNS\Application\Services\AuditLogService;
use TowerDNS\Application\Services\StepUpProofService;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Infrastructure\Http\Handler\WebAuthnDeleteHandler;
use TowerDNS\Infrastructure\Http\SessionSecurity;
use TowerDNS\Infrastructure\Http\StepUpRequestService;
use TowerDNS\Infrastructure\Persistence\DbalStepUpProofNonceRepository;
use Webauthn\CredentialRecord;
use Webauthn\TrustPath\EmptyTrustPath;

/** @psalm-api Runtime discovery by PHPUnit or local module loading is not statically visible. */
final class WebAuthnDeleteHandlerTest extends TestCase
{
    public function testThreeToTwoCanBeConfirmedByAnyOwnedFidoCredentialIncludingTheTarget(): void
    {
        [$response, $credentials] = $this->delete(['key-one', 'key-two', 'key-three'], 'key-one', 'key-one');

        self::assertSame(302, $response->getStatusCode());
        self::assertSame(['key-two', 'key-three'], $credentials->ids());
    }

    public function testTwoToOneRequiresAndAcceptsTheOtherFidoCredential(): void
    {
        [$response, $credentials] = $this->delete(['key-one', 'key-two'], 'key-one', 'key-two');

        self::assertSame(302, $response->getStatusCode());
        self::assertSame(['key-two'], $credentials->ids());
    }

    public function testTwoToOneRejectsSelfConfirmationAndOneToZeroIsNotSelfService(): void
    {
        [$selfConfirmed, $twoCredentials] = $this->delete(['key-one', 'key-two'], 'key-one', 'key-one');
        self::assertSame(['key-one', 'key-two'], $twoCredentials->ids());
        self::assertStringContainsString('use-another-key', $selfConfirmed->getHeaderLine('Location'));

        [$lastKey, $oneCredential] = $this->delete(['key-one'], 'key-one', 'key-one');
        self::assertSame(['key-one'], $oneCredential->ids());
        self::assertStringContainsString('last-key-self-service', $lastKey->getHeaderLine('Location'));
    }

    public function testTotpAndPasswordProofNeverAuthorizeFidoRemoval(): void
    {
        foreach (['totp', 'password'] as $method) {
            [$response, $credentials] = $this->delete(['key-one', 'key-two', 'key-three'], 'key-one', 'key-two', $method);

            self::assertSame(['key-one', 'key-two', 'key-three'], $credentials->ids());
            self::assertSame('/security/step-up', $response->getHeaderLine('Location'));
        }
    }

    /**
     * @param list<string> $ids
     *
     * @return array{ResponseInterface, TestWebAuthnCredentialRepository}
     */
    private function delete(array $ids, string $targetId, string $proofId, string $method = 'webauthn'): array
    {
        $connection  = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $credentials = new TestWebAuthnCredentialRepository('user-1', $ids);
        $clock       = new class implements ClockInterface {
            #[\Override]
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('@1768500000');
            }
        };
        $session         = new Session([]);
        $sessionSecurity = new SessionSecurity($clock);
        $proofs          = new StepUpProofService(str_repeat('k', 32), $clock, new DbalStepUpProofNonceRepository($connection));
        $stepUp          = new StepUpRequestService($sessionSecurity, $proofs);
        $targetHash      = hash('sha256', $targetId);
        $session         = $sessionSecurity->beginStepUp($session, 'user-1', StepUpAction::PROFILE_WEBAUTHN_DELETE, $targetHash, null);
        $sessionSecurity->completeStepUp($session, 'user-1', null, $method, $proofs, hash('sha256', $proofId));

        $auditRepository = $this->createMock(AuditLogRepositoryInterface::class);
        if ($method === 'webauthn' && count($ids) >= 2 && (count($ids) !== 2 || $proofId !== $targetId)) {
            $auditRepository->expects(self::once())->method('append');
        } else {
            $auditRepository->expects(self::never())->method('append');
        }

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('translate')->willReturnArgument(0);
        $handler = new WebAuthnDeleteHandler($connection, $credentials, $stepUp, new AuditLogService($auditRepository), $translator);
        $guard   = $this->createMock(CsrfGuardInterface::class);
        $guard->method('validateToken')->with('csrf')->willReturn(true);
        $encoded = rtrim(strtr(base64_encode($targetId), '+/', '-_'), '=');
        $route   = new Route('/unused', new class implements MiddlewareInterface {
            #[\Override]
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $handler->handle($request);
            }
        });
        $request = new ServerRequest()
            ->withMethod('POST')
            ->withParsedBody(['csrf_token' => 'csrf'])
            ->withAttribute(User::class, new User('user-1', 'person@example.test'))
            ->withAttribute(\Mezzio\Session\SessionInterface::class, $session)
            ->withAttribute(CsrfMiddleware::GUARD_ATTRIBUTE, $guard)
            ->withAttribute(RouteResult::class, RouteResult::fromRoute($route, ['credentialId' => $encoded]));

        return [$handler->handle($request), $credentials];
    }
}

final class TestWebAuthnCredentialRepository implements WebAuthnCredentialRepositoryInterface
{
    /** @var array<string, CredentialRecord> */
    private array $records = [];

    /** @param list<string> $ids */
    public function __construct(private readonly string $userId, array $ids)
    {
        foreach ($ids as $id) {
            $this->records[$id] = new CredentialRecord($id, 'public-key', [], 'none', EmptyTrustPath::create(), Uuid::fromString('00000000-0000-0000-0000-000000000000'), 'public-key', $this->userId, 0);
        }
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_keys($this->records);
    }

    #[\Override]
    public function findByUserId(string $userId): array
    {
        if ($userId !== $this->userId) {
            return [];
        }
        return array_map(static fn(CredentialRecord $source): array => [
            'credential_id'   => $source->publicKeyCredentialId,
            'name'            => 'Key',
            'created_at'      => '2026-01-01 00:00:00',
            'last_used_at'    => null,
            'attachment'      => 'cross-platform',
            'aaguid'          => '00000000-0000-0000-0000-000000000000',
            'transports'      => [],
            'backup_eligible' => false,
            'backup_state'    => false,
            'source'          => $source,
        ], array_values($this->records));
    }

    #[\Override]
    public function findByCredentialId(string $credentialId): ?CredentialRecord
    {
        return $this->records[$credentialId] ?? null;
    }

    #[\Override]
    public function findByCredentialIdForUser(string $credentialId, string $userId): ?CredentialRecord
    {
        return $userId === $this->userId ? ($this->records[$credentialId] ?? null) : null;
    }

    #[\Override]
    public function countAll(): int
    {
        return count($this->records);
    }

    #[\Override]
    public function countByUserId(string $userId): int
    {
        return $userId === $this->userId ? count($this->records) : 0;
    }

    #[\Override]
    public function save(string $userId, string $name, CredentialRecord $source, ?string $attachment = null, int $maxCredentials = 10): void {}

    #[\Override]
    public function saveDuringRecovery(string $userId, string $name, CredentialRecord $source, ?string $attachment, int $recoveryCeiling): void {}

    #[\Override]
    public function updateAfterAuthentication(CredentialRecord $source): void {}

    #[\Override]
    public function delete(string $credentialId, string $userId): void
    {
        if ($userId === $this->userId) {
            unset($this->records[$credentialId]);
        }
    }
}

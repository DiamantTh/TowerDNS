<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Http\Handler;

use Laminas\Diactoros\ServerRequest;
use Laminas\Translator\TranslatorInterface;
use Mezzio\Csrf\CsrfGuardInterface;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Session\SessionInterface;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Uid\Uuid;
use TowerDNS\Application\Repository\AuditLogRepositoryInterface;
use TowerDNS\Application\Repository\UserRepositoryInterface;
use TowerDNS\Application\Repository\WebAuthnCredentialRepositoryInterface;
use TowerDNS\Application\Services\AuditLogService;
use TowerDNS\Application\Services\WebAuthnService;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Infrastructure\Http\Handler\WebAuthnAuthBeginHandler;
use TowerDNS\Infrastructure\Http\SessionSecurity;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\TrustPath\EmptyTrustPath;

/** @psalm-api Runtime discovery by PHPUnit or local module loading is not statically visible. */
final class WebAuthnAuthBeginHandlerTest extends TestCase
{
    public function testPasswordlessBeginLoadsOnlyIdentifiedUsersCredentialsAndRequiresUv(): void
    {
        $session = new InMemorySession();
        $user    = new User('user-1', 'person@example.test');
        $users   = $this->createMock(UserRepositoryInterface::class);
        $users->expects(self::once())->method('findByEmail')->with('person@example.test')->willReturn($user);
        $credential       = new CredentialRecord('credential-one', 'public-key', ['usb'], 'none', EmptyTrustPath::create(), Uuid::fromString('00000000-0000-0000-0000-000000000000'), 'public-key', 'user-1', 0);
        $credentials      = $this->createMock(WebAuthnCredentialRepositoryInterface::class);
        $secondCredential = new CredentialRecord('credential-two', 'public-key', ['internal'], 'none', EmptyTrustPath::create(), Uuid::fromString('00000000-0000-0000-0000-000000000000'), 'public-key', 'user-1', 0);
        $credentials->expects(self::once())->method('findByUserId')->with('user-1')->willReturn([
            [
                'credential_id'   => 'credential-one', 'name' => 'Key', 'created_at' => '2026-01-01 00:00:00', 'last_used_at' => null,
                'attachment'      => 'cross-platform', 'aaguid' => '00000000-0000-0000-0000-000000000000', 'transports' => ['usb'],
                'backup_eligible' => false, 'backup_state' => false, 'source' => $credential,
            ],
            [
                'credential_id'   => 'credential-two', 'name' => 'Platform key', 'created_at' => '2026-01-02 00:00:00', 'last_used_at' => null,
                'attachment'      => 'platform', 'aaguid' => '00000000-0000-0000-0000-000000000000', 'transports' => ['internal'],
                'backup_eligible' => true, 'backup_state' => true, 'source' => $secondCredential,
            ],
        ]);
        $auditRepo  = $this->createMock(AuditLogRepositoryInterface::class);
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('translate')->willReturnArgument(0);
        $guard = $this->createMock(CsrfGuardInterface::class);
        $guard->method('validateToken')->with('csrf')->willReturn(true);
        $handler = new WebAuthnAuthBeginHandler(
            new WebAuthnService($this->serializer(), 'example.test', 'TowerDNS'),
            $credentials,
            $users,
            new SessionSecurity(new class implements ClockInterface {
                #[\Override]
                public function now(): \DateTimeImmutable
                {
                    return new \DateTimeImmutable('@1768500000');
                }
            }),
            new AuditLogService($auditRepo),
            $translator,
            new Psr16Cache(new ArrayAdapter()),
        );
        $request = new ServerRequest()->withMethod('POST')->withParsedBody(['csrf_token' => 'csrf', 'email' => ' Person@Example.Test '])
            ->withAttribute(SessionInterface::class, $session)
            ->withAttribute(CsrfMiddleware::GUARD_ATTRIBUTE, $guard);

        $response = $handler->handle($request);
        $payload  = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('required', $payload['userVerification']);
        self::assertCount(2, $payload['allowCredentials']);
        self::assertSame('passwordless', $session->get('mfa_type'));
        self::assertIsString($session->get('webauthn_auth_options'));
    }

    public function testUnknownUserGetsGenericFailureAndDoesNotLoadCredentialStore(): void
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->expects(self::once())->method('findByEmail')->with('unknown@example.test')->willReturn(null);
        $credentials = $this->createMock(WebAuthnCredentialRepositoryInterface::class);
        $credentials->expects(self::never())->method('findByUserId');
        $auditRepo = $this->createMock(AuditLogRepositoryInterface::class);
        $auditRepo->expects(self::once())->method('append')->with(self::callback(static fn($entry): bool => $entry->action === 'user.login.failed'), self::anything());
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('translate')->willReturnArgument(0);
        $guard = $this->createMock(CsrfGuardInterface::class);
        $guard->method('validateToken')->willReturn(true);
        $handler = new WebAuthnAuthBeginHandler(
            new WebAuthnService($this->serializer(), 'example.test', 'TowerDNS'),
            $credentials,
            $users,
            new SessionSecurity(new \TowerDNS\Infrastructure\Clock\SystemClock()),
            new AuditLogService($auditRepo),
            $translator,
            new Psr16Cache(new ArrayAdapter()),
        );
        $request = new ServerRequest()->withMethod('POST')->withParsedBody(['csrf_token' => 'csrf', 'email' => 'unknown@example.test'])
            ->withAttribute(SessionInterface::class, new InMemorySession())
            ->withAttribute(CsrfMiddleware::GUARD_ATTRIBUTE, $guard);

        $response = $handler->handle($request);

        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString('webauthn.error.authentication-failed', (string) $response->getBody());
    }

    private function serializer(): SerializerInterface
    {
        return new WebauthnSerializerFactory(new AttestationStatementSupportManager([
            new NoneAttestationStatementSupport(),
        ]))->create();
    }
}

final class InMemorySession implements SessionInterface
{
    /** @var array<string, mixed> */
    private array $data = [];

    /** @return array<string, mixed> */
    #[\Override]
    public function toArray(): array
    {
        return $this->data;
    }

    #[\Override]
    public function get(string $name, $default = null): mixed
    {
        return $this->data[$name] ?? $default;
    }

    #[\Override]
    public function has(string $name): bool
    {
        return array_key_exists($name, $this->data);
    }

    #[\Override]
    public function set(string $name, $value): void
    {
        $this->data[$name] = $value;
    }

    #[\Override]
    public function unset(string $name): void
    {
        unset($this->data[$name]);
    }

    #[\Override]
    public function clear(): void
    {
        $this->data = [];
    }

    #[\Override]
    public function hasChanged(): bool
    {
        return true;
    }

    #[\Override]
    public function regenerate(): SessionInterface
    {
        return $this;
    }

    #[\Override]
    public function isRegenerated(): bool
    {
        return true;
    }
}

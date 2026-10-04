<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Http\Handler;

use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Stream;
use Laminas\Translator\TranslatorInterface;
use Mezzio\Session\SessionInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\Serializer\SerializerInterface;
use TowerDNS\Application\Repository\AuditLogRepositoryInterface;
use TowerDNS\Application\Repository\UserRepositoryInterface;
use TowerDNS\Application\Repository\WebAuthnCredentialRepositoryInterface;
use TowerDNS\Application\Services\AuditLogService;
use TowerDNS\Application\Services\WebAuthnService;
use TowerDNS\Infrastructure\Http\Handler\WebAuthnAuthFinishHandler;
use TowerDNS\Infrastructure\Http\SessionSecurity;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\Denormalizer\WebauthnSerializerFactory;

/** @psalm-api Runtime discovery by PHPUnit or local module loading is not statically visible. */
final class WebAuthnAuthFinishHandlerTest extends TestCase
{
    public function testCredentialOwnershipIsCheckedAndAuthenticationChallengeIsOneShot(): void
    {
        $session  = new FinishTestSession();
        $security = new SessionSecurity(new \TowerDNS\Infrastructure\Clock\SystemClock());
        $security->beginMfa($session, 'user-1', 'passwordless');
        $webAuthn = new WebAuthnService($this->serializer(), 'example.test', 'TowerDNS');
        $session->set('webauthn_auth_options', $webAuthn->serializeRequestOptions($webAuthn->createAuthenticationOptions(['fake-credential'])));

        $credentials = $this->createMock(WebAuthnCredentialRepositoryInterface::class);
        $credentials->expects(self::once())->method('findByCredentialIdForUser')->with('fake-credential', 'user-1')->willReturn(null);
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->expects(self::never())->method('updateLastLoginAt');
        $auditRepo  = $this->createMock(AuditLogRepositoryInterface::class);
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('translate')->willReturnArgument(0);
        $handler = new WebAuthnAuthFinishHandler(
            $webAuthn,
            $credentials,
            $users,
            new AuditLogService($auditRepo),
            $translator,
            $security,
            new Psr16Cache(new ArrayAdapter()),
        );
        $body    = json_encode(['id' => 'ZmFrZS1jcmVkZW50aWFs', 'rawId' => 'ZmFrZS1jcmVkZW50aWFs'], JSON_THROW_ON_ERROR);
        $request = $this->request($body, $session);

        $first = $handler->handle($request);
        self::assertInstanceOf(JsonResponse::class, $first);
        self::assertSame(422, $first->getStatusCode());
        self::assertFalse($session->has('webauthn_auth_options'));

        $replay = $handler->handle($request);
        self::assertSame(400, $replay->getStatusCode());
    }

    public function testExpiredPasswordlessMfaChallengeIsRejected(): void
    {
        $session = new FinishTestSession();
        $session->set('mfa_pending', 'user-1');
        $session->set('mfa_type', 'passwordless');
        $session->set('mfa_pending_started_at', time() - 301);
        $session->set('webauthn_auth_options', '{}');

        $credentials = $this->createMock(WebAuthnCredentialRepositoryInterface::class);
        $credentials->expects(self::never())->method('findByCredentialIdForUser');
        $handler = new WebAuthnAuthFinishHandler(
            new WebAuthnService($this->serializer(), 'example.test', 'TowerDNS'),
            $credentials,
            $this->createMock(UserRepositoryInterface::class),
            new AuditLogService($this->createMock(AuditLogRepositoryInterface::class)),
            $this->translator(),
            new SessionSecurity(new \TowerDNS\Infrastructure\Clock\SystemClock()),
            new Psr16Cache(new ArrayAdapter()),
        );

        $response = $handler->handle($this->request('{}', $session));

        self::assertSame(400, $response->getStatusCode());
        self::assertFalse($session->has('mfa_pending'));
    }

    private function request(string $body, FinishTestSession $session): ServerRequest
    {
        $stream = new Stream('php://temp', 'r+');
        $stream->write($body);
        $stream->rewind();

        return new ServerRequest()->withMethod('POST')->withBody($stream)->withAttribute(SessionInterface::class, $session);
    }

    private function serializer(): SerializerInterface
    {
        return new WebauthnSerializerFactory(new AttestationStatementSupportManager([
            new NoneAttestationStatementSupport(),
        ]))->create();
    }

    private function translator(): TranslatorInterface
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('translate')->willReturnArgument(0);
        return $translator;
    }
}

final class FinishTestSession implements SessionInterface
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

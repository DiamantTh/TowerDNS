<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Http\Handler;

use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Stream;
use Laminas\Translator\TranslatorInterface;
use Mezzio\Csrf\CsrfGuardInterface;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Session\SessionInterface;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Serializer\SerializerInterface;
use TowerDNS\Application\DTO\StepUpAction;
use TowerDNS\Application\Repository\AuditLogRepositoryInterface;
use TowerDNS\Application\Repository\StepUpProofNonceRepositoryInterface;
use TowerDNS\Application\Repository\SystemSettingsRepositoryInterface;
use TowerDNS\Application\Repository\WebAuthnCredentialRepositoryInterface;
use TowerDNS\Application\Services\AuditLogService;
use TowerDNS\Application\Services\StepUpProofService;
use TowerDNS\Application\Services\WebAuthnService;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Infrastructure\Http\Handler\WebAuthnRegisterBeginHandler;
use TowerDNS\Infrastructure\Http\Handler\WebAuthnRegisterFinishHandler;
use TowerDNS\Infrastructure\Http\SessionSecurity;
use TowerDNS\Infrastructure\Http\StepUpRequestService;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\Denormalizer\WebauthnSerializerFactory;

/** @psalm-api Runtime discovery by PHPUnit or local module loading is not statically visible. */
final class WebAuthnEnrollmentHandlerTest extends TestCase
{
    public function testHardwareKeyEnrollmentUsesAUserBoundWebAuthnStepUpAndShortPendingState(): void
    {
        $user        = new User('user-1', 'person@example.test');
        $session     = new EnrollmentTestSession();
        $stepUp      = $this->stepUp($session, $user->id, StepUpAction::PROFILE_WEBAUTHN_ENROLL, $user->id);
        $credentials = $this->createMock(WebAuthnCredentialRepositoryInterface::class);
        $credentials->expects(self::once())->method('countByUserId')->with($user->id)->willReturn(0);
        $credentials->expects(self::once())->method('findByUserId')->with($user->id)->willReturn([]);
        $settings = $this->createMock(SystemSettingsRepositoryInterface::class);
        $settings->method('get')->willReturnCallback(static fn(string $key, mixed $default = null): mixed => $default);
        $auditRepo = $this->createMock(AuditLogRepositoryInterface::class);
        $auditRepo->expects(self::once())->method('append')->with(
            self::callback(static fn($entry): bool => $entry->action === 'security.webauthn.enrollment.started'),
            self::anything(),
        );
        $handler = new WebAuthnRegisterBeginHandler(
            $this->webAuthn(),
            $credentials,
            $this->translator(),
            $stepUp,
            $settings,
            new AuditLogService($auditRepo),
        );
        $request = new ServerRequest()->withMethod('POST')->withParsedBody([
            'csrf_token' => 'csrf',
            'name'       => 'Main security key',
            'method'     => 'security_key',
        ])->withAttribute(User::class, $user)
            ->withAttribute(SessionInterface::class, $session)
            ->withAttribute(CsrfMiddleware::GUARD_ATTRIBUTE, $this->csrfGuard());

        $response = $handler->handle($request);
        $payload  = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $pending  = $session->get('webauthn_register_pending');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('cross-platform', $payload['authenticatorSelection']['authenticatorAttachment']);
        self::assertSame('preferred', $payload['authenticatorSelection']['residentKey']);
        self::assertSame('required', $payload['authenticatorSelection']['userVerification']);
        self::assertIsArray($pending);
        self::assertSame($user->id, $pending['user_id']);
        self::assertSame('hardware_security_key', $pending['purpose']);
        self::assertSame('Main security key', $pending['label']);
        self::assertSame(300, $pending['expires_at'] - $pending['created_at']);
    }

    public function testExpiredRegistrationIsConsumedBeforeAnyCredentialCanBeSaved(): void
    {
        $user    = new User('user-1', 'person@example.test');
        $session = new EnrollmentTestSession();
        $now     = time();
        $session->set('webauthn_register_pending', [
            'user_id'    => $user->id,
            'options'    => '{}',
            'label'      => 'Expired key',
            'created_at' => $now - 301,
            'expires_at' => $now - 1,
            'purpose'    => 'passkey',
        ]);
        $credentials = $this->createMock(WebAuthnCredentialRepositoryInterface::class);
        $credentials->expects(self::never())->method('save');
        $handler = new WebAuthnRegisterFinishHandler(
            $this->webAuthn(),
            $credentials,
            $this->createMock(SystemSettingsRepositoryInterface::class),
            $this->stepUp($session, $user->id, StepUpAction::PROFILE_WEBAUTHN_ENROLL, $user->id),
            new AuditLogService($this->createMock(AuditLogRepositoryInterface::class)),
            $this->translator(),
        );
        $request = $this->jsonRequest(['csrf_token' => 'csrf'], $session, $user);

        $first = $handler->handle($request);
        $again = $handler->handle($request);

        self::assertSame(400, $first->getStatusCode());
        self::assertSame(400, $again->getStatusCode());
        self::assertFalse($session->has('webauthn_register_pending'));
    }

    private function stepUp(EnrollmentTestSession $session, string $userId, string $action, string $target): StepUpRequestService
    {
        $clock = new class implements ClockInterface {
            #[\Override]
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable();
            }
        };
        $proofs = new StepUpProofService('01234567890123456789012345678901', $clock, $this->createMock(StepUpProofNonceRepositoryInterface::class));
        $session->set('step_up_proof', $proofs->issue($userId, $action, $target, null, 'webauthn', hash('sha256', 'key'))->toArray());

        return new StepUpRequestService(new SessionSecurity($clock), $proofs);
    }

    /** @param array<string, mixed> $body */
    private function jsonRequest(array $body, EnrollmentTestSession $session, User $user): ServerRequest
    {
        $stream = new Stream('php://temp', 'r+');
        $stream->write(json_encode($body, JSON_THROW_ON_ERROR));
        $stream->rewind();

        return new ServerRequest()->withMethod('POST')->withBody($stream)
            ->withAttribute(User::class, $user)
            ->withAttribute(SessionInterface::class, $session)
            ->withAttribute(CsrfMiddleware::GUARD_ATTRIBUTE, $this->csrfGuard());
    }

    private function csrfGuard(): CsrfGuardInterface
    {
        $guard = $this->createMock(CsrfGuardInterface::class);
        $guard->method('validateToken')->with('csrf')->willReturn(true);
        $guard->method('generateToken')->willReturn('csrf');

        return $guard;
    }

    private function translator(): TranslatorInterface
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('translate')->willReturnArgument(0);

        return $translator;
    }

    private function webAuthn(): WebAuthnService
    {
        $serializer = new WebauthnSerializerFactory(new AttestationStatementSupportManager([
            new NoneAttestationStatementSupport(),
        ]))->create();

        self::assertInstanceOf(SerializerInterface::class, $serializer);

        return new WebAuthnService($serializer, 'example.test', 'TowerDNS');
    }
}

final class EnrollmentTestSession implements SessionInterface
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

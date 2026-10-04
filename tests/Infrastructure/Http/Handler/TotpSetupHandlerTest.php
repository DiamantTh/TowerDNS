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
use Mezzio\Session\SessionInterface;
use Mezzio\Template\TemplateRendererInterface;
use OTPHP\TOTP;
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
use TowerDNS\Infrastructure\Clock\SystemClock;
use TowerDNS\Infrastructure\Http\Handler\TotpSetupHandler;
use TowerDNS\Infrastructure\Http\SessionSecurity;
use TowerDNS\Infrastructure\Http\StepUpRequestService;
use TowerDNS\Infrastructure\Persistence\DbalStepUpProofNonceRepository;
use TowerDNS\Infrastructure\Persistence\DbalTotpCredentialRepository;
use TowerDNS\Infrastructure\Persistence\SchemaManager;
use Webauthn\CredentialRecord;
use Webauthn\TrustPath\EmptyTrustPath;

/** @psalm-api Runtime discovery by PHPUnit or local module loading is not statically visible. */
final class TotpSetupHandlerTest extends TestCase
{
    public function testTOTPIsOnlyActivatedAfterVerificationAndDisableDoesNotRenderAnUnknownSecret(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $schema     = new SchemaManager($connection);
        $schema->createTablesIfNotExist();
        $schema->seedSystemRoles();
        $schema->seedFirstUser('33333333-3333-4333-8333-333333333333', 'person@example.test', 'password-hash');

        $clock       = new SystemClock();
        $session     = new TotpSetupTestSession();
        $user        = new User('33333333-3333-4333-8333-333333333333', 'person@example.test');
        $credentials = new DbalTotpCredentialRepository($connection, $clock);
        $settings    = $this->createMock(SystemSettingsRepositoryInterface::class);
        $settings->method('get')->willReturnCallback(static fn(string $key, mixed $default = null): mixed => $key === 'security.totp.max_credentials_per_user' ? 5 : $default);
        $secrets  = new TotpSecretService($credentials, new TotpService($clock), $this->cipher(), $settings);
        $proofs   = new StepUpProofService('01234567890123456789012345678901', $clock, new DbalStepUpProofNonceRepository($connection));
        $stepUp   = new StepUpRequestService(new SessionSecurity($clock), $proofs);
        $webAuthn = $this->createMock(WebAuthnCredentialRepositoryInterface::class);
        $webAuthn->method('countByUserId')->with($user->id)->willReturn(1);
        $fidoCredential = new CredentialRecord('fido-credential', 'public-key', [], 'none', EmptyTrustPath::create(), Uuid::fromString('00000000-0000-0000-0000-000000000000'), 'public-key', $user->id, 0);
        $webAuthn->method('findByUserId')->with($user->id)->willReturn([[
            'credential_id'   => 'fido-credential', 'name' => 'Key', 'created_at' => '2026-01-01 00:00:00', 'last_used_at' => null,
            'attachment'      => 'cross-platform', 'aaguid' => '00000000-0000-0000-0000-000000000000', 'transports' => [],
            'backup_eligible' => false, 'backup_state' => false, 'source' => $fidoCredential,
        ]]);
        $auditRepo = $this->createMock(AuditLogRepositoryInterface::class);
        $auditRepo->expects(self::exactly(2))->method('append')->with(self::anything(), self::anything());
        $rendered = [];
        $renderer = $this->createMock(TemplateRendererInterface::class);
        $renderer->method('render')->willReturnCallback(static function (string $template, array $data) use (&$rendered): string {
            $rendered = $data;
            return '';
        });
        $handler = new TotpSetupHandler(
            $connection,
            $renderer,
            $secrets,
            new TotpService($clock),
            new AuditLogService($auditRepo),
            $this->translator(),
            $stepUp,
            $webAuthn,
        );
        $this->setProof($session, $proofs, $user->id, StepUpAction::PROFILE_TOTP_ENROLL, $user->id);

        self::assertSame(200, $handler->handle($this->getSetupRequest($user, $session))->getStatusCode());
        $pending = $session->get('totp_setup_pending');
        self::assertIsArray($pending);
        self::assertSame($user->id, $pending['user_id']);
        self::assertSame('totp.enrollment', $pending['purpose']);
        self::assertSame($pending['secret'], $rendered['secret']);

        $validCode   = $this->currentCode($pending['secret'], $clock);
        $invalidCode = $validCode === '00000000' ? '11111111' : '00000000';
        self::assertSame(401, $handler->handle($this->postRequest($user, $session, ['action' => 'enable', 'code' => $invalidCode]))->getStatusCode());
        self::assertSame(0, $secrets->count($user->id), 'Invalid TOTP must never become durable.');
        self::assertTrue($session->has('totp_setup_pending'));

        self::assertSame(200, $handler->handle($this->postRequest($user, $session, ['action' => 'enable', 'code' => $validCode]))->getStatusCode());
        self::assertSame(1, $secrets->count($user->id));
        self::assertFalse($session->has('totp_setup_pending'));
        $credentialId = $secrets->list($user->id)[0]['id'];

        $this->setProof($session, $proofs, $user->id, StepUpAction::PROFILE_TOTP_DELETE, hash('sha256', $credentialId), 'webauthn', hash('sha256', 'fido-credential'));
        self::assertSame(200, $handler->handle($this->postRequest($user, $session, ['action' => 'delete', 'credential_id' => $credentialId]))->getStatusCode());
        self::assertSame(0, $secrets->count($user->id));
        self::assertFalse($session->has('totp_setup_pending'));
        self::assertNull($rendered['secret']);
        self::assertNull($rendered['provisioningUri']);

        $this->setProof($session, $proofs, $user->id, StepUpAction::PROFILE_TOTP_ENROLL, $user->id);
        self::assertSame(200, $handler->handle($this->getSetupRequest($user, $session))->getStatusCode());
        $newPending = $session->get('totp_setup_pending');
        self::assertIsArray($newPending);
        self::assertSame($newPending['secret'], $rendered['secret']);
        self::assertNotSame($pending['secret'], $newPending['secret']);
    }

    private function setProof(TotpSetupTestSession $session, StepUpProofService $proofs, string $userId, string $action, string $target, string $method = 'webauthn', ?string $credentialIdHash = null): void
    {
        $credentialIdHash ??= hash('sha256', 'fido-credential');
        $session->set('step_up_proof', $proofs->issue($userId, $action, $target, null, $method, $credentialIdHash)->toArray());
    }

    private function currentCode(string $secret, ClockInterface $clock): string
    {
        if ($secret === '') {
            throw new \InvalidArgumentException('A TOTP secret is required.');
        }

        $totp = TOTP::createFromSecret($secret, $clock);
        $totp->setDigits(8);
        $totp->setDigest('sha512');
        $totp->setPeriod(30);

        return $totp->now();
    }

    private function getSetupRequest(User $user, TotpSetupTestSession $session): ServerRequest
    {
        return new ServerRequest()->withMethod('GET')->withQueryParams(['setup' => '1', 'label' => 'Backup phone'])
            ->withAttribute(User::class, $user)
            ->withAttribute(SessionInterface::class, $session)
            ->withAttribute(CsrfMiddleware::GUARD_ATTRIBUTE, $this->csrfGuard());
    }

    /** @param array<string, string> $fields */
    private function postRequest(User $user, TotpSetupTestSession $session, array $fields): ServerRequest
    {
        return new ServerRequest()->withMethod('POST')->withParsedBody(['csrf_token' => 'csrf', ...$fields])
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

    private function cipher(): CredentialEncryptorInterface
    {
        return new class implements CredentialEncryptorInterface {
            #[\Override]
            public function encrypt(string $plaintext): string
            {
                return base64_encode($plaintext);
            }

            #[\Override]
            public function decrypt(string $ciphertext): string
            {
                $plaintext = base64_decode($ciphertext, true);
                if ($plaintext === false) {
                    throw new \RuntimeException('Invalid ciphertext.');
                }
                return $plaintext;
            }

            #[\Override]
            public function wipe(string &$plaintext): void
            {
                $plaintext = '';
            }
        };
    }
}

final class TotpSetupTestSession implements SessionInterface
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

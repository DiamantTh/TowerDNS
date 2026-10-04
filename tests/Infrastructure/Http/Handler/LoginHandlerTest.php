<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Http\Handler;

use Laminas\Diactoros\Response\RedirectResponse;
use Laminas\Diactoros\ServerRequest;
use Laminas\Translator\TranslatorInterface;
use Mezzio\Csrf\CsrfGuardInterface;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Session\SessionInterface;
use Mezzio\Template\TemplateRendererInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use TowerDNS\Application\Contracts\CredentialEncryptorInterface;
use TowerDNS\Application\Repository\AuditLogRepositoryInterface;
use TowerDNS\Application\Repository\SystemSettingsRepositoryInterface;
use TowerDNS\Application\Repository\TotpCredentialRepositoryInterface;
use TowerDNS\Application\Repository\UserRepositoryInterface;
use TowerDNS\Application\Repository\WebAuthnCredentialRepositoryInterface;
use TowerDNS\Application\Services\AuditLogService;
use TowerDNS\Application\Services\TotpSecretService;
use TowerDNS\Application\Services\TotpService;
use TowerDNS\Domain\Auth\User;
use TowerDNS\Infrastructure\Clock\SystemClock;
use TowerDNS\Infrastructure\Http\Handler\LoginHandler;
use TowerDNS\Infrastructure\Http\SessionSecurity;

/** @psalm-api Runtime discovery by PHPUnit or local module loading is not statically visible. */
final class LoginHandlerTest extends TestCase
{
    private static ?string $passwordHash = null;

    public function testPasswordLoginPrefersWebAuthnWhenTotpIsAlsoConfigured(): void
    {
        [$handler, $session] = $this->handler(hasWebAuthn: true, hasTotp: true);

        $response = $handler->handle($this->request($session));

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/login/webauthn', $response->getHeaderLine('Location'));
        self::assertSame('webauthn', $session->get('mfa_type'));
        self::assertSame('user-1', $session->get('mfa_pending'));
    }

    public function testPasswordLoginUsesTotpWhenNoWebAuthnCredentialIsRegistered(): void
    {
        [$handler, $session] = $this->handler(hasWebAuthn: false, hasTotp: true);

        $response = $handler->handle($this->request($session));

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/login/totp', $response->getHeaderLine('Location'));
        self::assertSame('totp', $session->get('mfa_type'));
    }

    /** @return array{LoginHandler, LoginTestSession} */
    private function handler(bool $hasWebAuthn, bool $hasTotp): array
    {
        $user  = new User('user-1', 'person@example.test');
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('fetchPasswordHash')->with('person@example.test')->willReturn(self::$passwordHash ??= password_hash(
            'correct horse battery staple',
            PASSWORD_ARGON2ID,
            ['memory_cost' => 131072, 'time_cost' => 4, 'threads' => 4],
        ));
        $users->method('findByEmail')->with('person@example.test')->willReturn($user);
        $users->expects(self::never())->method('updateLastLoginAt');

        $webAuthn = $this->createMock(WebAuthnCredentialRepositoryInterface::class);
        $webAuthn->method('findByUserId')->with($user->id)->willReturn($hasWebAuthn ? [[
            'credential_id'   => 'shared-credential',
            'name'            => 'Shared key',
            'created_at'      => '2026-01-01 00:00:00',
            'last_used_at'    => null,
            'attachment'      => 'cross-platform',
            'aaguid'          => '00000000-0000-0000-0000-000000000000',
            'transports'      => ['usb'],
            'backup_eligible' => false,
            'backup_state'    => false,
            'source'          => new \Webauthn\CredentialRecord(
                'shared-credential',
                'public-key',
                ['usb'],
                'none',
                \Webauthn\TrustPath\EmptyTrustPath::create(),
                \Symfony\Component\Uid\Uuid::fromString('00000000-0000-0000-0000-000000000000'),
                'public-key',
                $user->id,
                0,
            ),
        ]] : []);

        $totpRepo = new LoginTotpCredentialRepository($hasTotp);
        $settings = $this->createMock(SystemSettingsRepositoryInterface::class);
        $settings->method('get')->willReturn(5);
        $totpSecrets = new TotpSecretService($totpRepo, new TotpService(new SystemClock()), $this->cipher(), $settings);
        $auditRepo   = $this->createMock(AuditLogRepositoryInterface::class);
        $auditRepo->expects(self::never())->method('append');
        $renderer   = $this->createMock(TemplateRendererInterface::class);
        $cache      = new Psr16Cache(new ArrayAdapter());
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('translate')->willReturnArgument(0);
        $guard = $this->createMock(CsrfGuardInterface::class);
        $guard->method('validateToken')->with('csrf')->willReturn(true);
        $guard->method('generateToken')->willReturn('csrf');
        $session = new LoginTestSession();

        return [new LoginHandler(
            $renderer,
            $users,
            $cache,
            $webAuthn,
            $totpSecrets,
            new AuditLogService($auditRepo),
            $translator,
            new SessionSecurity(new SystemClock()),
        ), $session];
    }

    private function request(LoginTestSession $session): ServerRequest
    {
        return new ServerRequest()->withMethod('POST')->withParsedBody([
            'csrf_token' => 'csrf',
            'email'      => 'person@example.test',
            'password'   => 'correct horse battery staple',
        ])->withAttribute(SessionInterface::class, $session)
            ->withAttribute(CsrfMiddleware::GUARD_ATTRIBUTE, $this->csrfGuard());
    }

    private function csrfGuard(): CsrfGuardInterface
    {
        $guard = $this->createMock(CsrfGuardInterface::class);
        $guard->method('validateToken')->with('csrf')->willReturn(true);
        $guard->method('generateToken')->willReturn('csrf');
        return $guard;
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

final readonly class LoginTotpCredentialRepository implements TotpCredentialRepositoryInterface
{
    public function __construct(private bool $enabled) {}

    #[\Override]
    public function findByUserId(string $userId): array
    {
        return $this->enabled ? [[
            'id'               => 'totp-1',
            'label'            => 'Phone',
            'created_at'       => '2026-01-01 00:00:00',
            'last_used_at'     => null,
            'secret_encrypted' => base64_encode('JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP'),
        ]] : [];
    }

    #[\Override]
    public function countByUserId(string $userId): int
    {
        return $this->enabled ? 1 : 0;
    }

    #[\Override]
    public function save(string $id, string $userId, string $encryptedSecret, string $label, int $maxCredentials): void {}

    #[\Override]
    public function markUsed(string $id, string $userId): void {}

    #[\Override]
    public function delete(string $id, string $userId): void {}
}

final class LoginTestSession implements SessionInterface
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

<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Application\Services;

use OTPHP\TOTP;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use TowerDNS\Application\Contracts\CredentialEncryptorInterface;
use TowerDNS\Application\Repository\UserRepositoryInterface;
use TowerDNS\Application\Services\TotpSecretService;
use TowerDNS\Application\Services\TotpService;

final class TotpSecretServiceTest extends TestCase
{
    public function testEnablePersistsCiphertextAndVerificationUsesShortLivedPlaintext(): void
    {
        $secret     = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
        $ciphertext = null;
        $users      = $this->createMock(UserRepositoryInterface::class);
        $users->expects(self::once())->method('saveEncryptedTotpSecret')->with('user-1', self::callback(function (?string $stored) use (&$ciphertext, $secret): bool {
            $ciphertext = $stored;
            return is_string($stored) && $stored !== $secret && $stored !== '';
        }));

        $service = $this->service($users);
        $service->enable('user-1', $secret);

        self::assertIsString($ciphertext);
        self::assertNotSame($secret, $ciphertext);

        $users->expects(self::once())->method('fetchEncryptedTotpSecret')->with('user-1')->willReturn($ciphertext);
        self::assertTrue($service->verify('user-1', $this->code($secret)));
    }

    public function testRejectsIncorrectCodesAndManipulatedCiphertext(): void
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('fetchEncryptedTotpSecret')->willReturn('not-a-valid-ciphertext');

        self::assertFalse($this->service($users)->verify('user-1', '12345678'));
    }

    public function testCiphertextCannotBeVerifiedWithAnotherApplicationKey(): void
    {
        $secret = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
        $blob   = new TestTotpCipher('first-key')->encrypt($secret);
        $users  = $this->createMock(UserRepositoryInterface::class);
        $users->method('fetchEncryptedTotpSecret')->willReturn($blob);

        $other = new TotpSecretService(
            $users,
            new TotpService($this->clock()),
            new TestTotpCipher('second-key'),
        );

        self::assertFalse($other->verify('user-1', $this->code($secret)));
    }

    public function testDisableClearsTheEncryptedSecret(): void
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->expects(self::once())->method('saveEncryptedTotpSecret')->with('user-1', null);

        $this->service($users)->disable('user-1');
    }

    private function service(UserRepositoryInterface $users): TotpSecretService
    {
        return new TotpSecretService(
            $users,
            new TotpService($this->clock()),
            new TestTotpCipher('test-key'),
        );
    }

    /** @param non-empty-string $secret */
    private function code(string $secret): string
    {
        $totp = TOTP::create($secret, 30, 'sha512', 8, 0, $this->clock());
        return $totp->now();
    }

    private function clock(): ClockInterface
    {
        return new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('@1768500000');
            }
        };
    }
}

final readonly class TestTotpCipher implements CredentialEncryptorInterface
{
    public function __construct(private string $key) {}

    public function encrypt(string $plaintext): string
    {
        return base64_encode($this->key . ':' . $plaintext);
    }

    public function decrypt(string $ciphertext): string
    {
        $decoded = base64_decode($ciphertext, true);
        $prefix  = $this->key . ':';
        if (!is_string($decoded) || !str_starts_with($decoded, $prefix)) {
            throw new \RuntimeException('Invalid ciphertext.');
        }
        return substr($decoded, strlen($prefix));
    }

    public function wipe(string &$plaintext): void
    {
        $plaintext = '';
    }
}

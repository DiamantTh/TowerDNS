<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Application\Services;

use OTPHP\TOTP;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use TowerDNS\Application\Contracts\CredentialEncryptorInterface;
use TowerDNS\Application\Exception\TotpCredentialLimitException;
use TowerDNS\Application\Repository\SystemSettingsRepositoryInterface;
use TowerDNS\Application\Repository\TotpCredentialRepositoryInterface;
use TowerDNS\Application\Services\TotpSecretService;
use TowerDNS\Application\Services\TotpService;

/** @psalm-api Runtime discovery by PHPUnit or local module loading is not statically visible. */
final class TotpSecretServiceTest extends TestCase
{
    public function testCredentialIsStoredEncryptedAndUsedForVerification(): void
    {
        $secret  = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
        $repo    = new InMemoryTotpCredentialRepository();
        $cipher  = new TestTotpCipher('test-key');
        $service = $this->service($repo, $cipher);

        $id = $service->enable('user-1', $secret);

        self::assertNotSame($secret, $repo->rows[$id]['secret_encrypted']);
        self::assertTrue($service->verify('user-1', $this->code($secret)));
        self::assertSame(1, $repo->markUsedCount);
        self::assertSame($id, $repo->lastUsedId);
    }

    public function testMultipleCredentialsCanBeIndependentlyUsedAndRevoked(): void
    {
        $repo         = new InMemoryTotpCredentialRepository();
        $service      = $this->service($repo, new TestTotpCipher('test-key'));
        $firstSecret  = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
        $secondSecret = 'KRSXG5DSNFXGOIDBMJSGCY3QOZUWY4R2';

        $first  = $service->enable('user-1', $firstSecret, 'Phone');
        $second = $service->enable('user-1', $secondSecret, 'Security tablet');

        self::assertCount(2, $service->list('user-1'));
        self::assertTrue($service->verify('user-1', $this->code($secondSecret)));
        self::assertSame($second, $repo->lastUsedId);
        $service->delete('user-1', $first);

        self::assertCount(1, $service->list('user-1'));
        self::assertSame($second, $service->list('user-1')[0]['id']);
        self::assertFalse($service->verify('user-1', $this->code($firstSecret)));
    }

    public function testConfiguredLimitBlocksOnlyAdditionalEnrollment(): void
    {
        $repo    = new InMemoryTotpCredentialRepository();
        $service = $this->service($repo, new TestTotpCipher('test-key'), 1);
        $service->enable('user-1', 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP');

        try {
            $service->enable('user-1', 'KRSXG5DSNFXGOIDBMJSGCY3QOZUWY4R2');
            self::fail('The configured credential limit must be enforced.');
        } catch (TotpCredentialLimitException) {
            self::assertCount(1, $service->list('user-1'));
        }
    }

    public function testCorruptCiphertextAndIncorrectCodesFailClosed(): void
    {
        $repo                 = new InMemoryTotpCredentialRepository();
        $repo->rows['broken'] = [
            'id'               => 'broken',
            'user_id'          => 'user-1',
            'label'            => 'Broken',
            'secret_encrypted' => 'not-a-valid-ciphertext',
            'created_at'       => '2026-01-01 00:00:00',
            'last_used_at'     => null,
        ];

        self::assertFalse($this->service($repo, new TestTotpCipher('test-key'))->verify('user-1', '12345678'));
    }

    public function testCiphertextCannotBeVerifiedWithAnotherApplicationKey(): void
    {
        $secret              = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
        $repo                = new InMemoryTotpCredentialRepository();
        $repo->rows['keyed'] = [
            'id'               => 'keyed',
            'user_id'          => 'user-1',
            'label'            => 'Keyed',
            'secret_encrypted' => new TestTotpCipher('first-key')->encrypt($secret),
            'created_at'       => '2026-01-01 00:00:00',
            'last_used_at'     => null,
        ];

        self::assertFalse($this->service($repo, new TestTotpCipher('second-key'))->verify('user-1', $this->code($secret)));
    }

    private function service(InMemoryTotpCredentialRepository $repo, CredentialEncryptorInterface $cipher, int $limit = 5): TotpSecretService
    {
        $settings = $this->createMock(SystemSettingsRepositoryInterface::class);
        $settings->method('get')->willReturnCallback(static fn(string $key, mixed $default): mixed => $key === 'security.totp.max_credentials_per_user' ? $limit : $default);

        return new TotpSecretService($repo, new TotpService($this->clock()), $cipher, $settings);
    }

    /** @param non-empty-string $secret */
    private function code(string $secret): string
    {
        return TOTP::create($secret, 30, 'sha512', 8, 0, $this->clock())->now();
    }

    private function clock(): ClockInterface
    {
        return new class implements ClockInterface {
            #[\Override]
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('@1768500000');
            }
        };
    }
}

final class InMemoryTotpCredentialRepository implements TotpCredentialRepositoryInterface
{
    /** @var array<string, array{id: string, user_id: string, label: string, secret_encrypted: string, created_at: string, last_used_at: string|null}> */
    public array $rows         = [];
    public int $markUsedCount  = 0;
    public ?string $lastUsedId = null;

    /** @return list<array{id: string, label: string, secret_encrypted: string, created_at: string, last_used_at: string|null}> */
    #[\Override]
    public function findByUserId(string $userId): array
    {
        $rows = array_filter($this->rows, static fn(array $row): bool => $row['user_id'] === $userId);

        return array_values(array_map(static fn(array $row): array => [
            'id'               => $row['id'],
            'label'            => $row['label'],
            'secret_encrypted' => $row['secret_encrypted'],
            'created_at'       => $row['created_at'],
            'last_used_at'     => $row['last_used_at'],
        ], $rows));
    }

    #[\Override]
    public function countByUserId(string $userId): int
    {
        return count($this->findByUserId($userId));
    }

    #[\Override]
    public function save(string $id, string $userId, string $encryptedSecret, string $label, int $maxCredentials): void
    {
        if ($this->countByUserId($userId) >= $maxCredentials) {
            throw new TotpCredentialLimitException('Limit reached.');
        }
        $this->rows[$id] = ['id' => $id, 'user_id' => $userId, 'label' => $label, 'secret_encrypted' => $encryptedSecret, 'created_at' => '2026-01-01 00:00:00', 'last_used_at' => null];
    }

    #[\Override]
    public function markUsed(string $id, string $userId): void
    {
        ++$this->markUsedCount;
        $this->lastUsedId = $id;
        if (isset($this->rows[$id]) && $this->rows[$id]['user_id'] === $userId) {
            $this->rows[$id]['last_used_at'] = '2026-01-01 00:00:01';
        }
    }

    #[\Override]
    public function delete(string $id, string $userId): void
    {
        if (($this->rows[$id]['user_id'] ?? null) === $userId) {
            unset($this->rows[$id]);
        }
    }
}

final readonly class TestTotpCipher implements CredentialEncryptorInterface
{
    public function __construct(private string $key) {}

    #[\Override]
    public function encrypt(string $plaintext): string
    {
        return base64_encode($this->key . ':' . $plaintext);
    }

    #[\Override]
    public function decrypt(string $ciphertext): string
    {
        $decoded = base64_decode($ciphertext, true);
        $prefix  = $this->key . ':';
        if ($decoded === false) {
            throw new \RuntimeException('Invalid ciphertext.');
        }
        if (!str_starts_with($decoded, $prefix)) {
            throw new \RuntimeException('Invalid ciphertext.');
        }
        return substr($decoded, strlen($prefix));
    }

    #[\Override]
    public function wipe(string &$plaintext): void
    {
        $plaintext = '';
    }
}

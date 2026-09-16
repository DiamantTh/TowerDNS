<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Configuration;

use PHPUnit\Framework\TestCase;
use TowerDNS\Application\Services\CredentialService;
use TowerDNS\Infrastructure\Configuration\AtomicConfigurationWriter;

final class AtomicConfigurationWriterTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/towerdns-config-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testWritesPrivateConfigurationAtomically(): void
    {
        $path = $this->directory . '/config.local.toml';
        new AtomicConfigurationWriter()->write($path, "[security]\nvalue = \"private\"");

        self::assertFileExists($path);
        self::assertSame(0o600, fileperms($path) & 0o777);
        self::assertSame([], glob($path . '.tmp.*'));
    }

    public function testCredentialServiceRejectsInvalidKeysWithoutLeakingValues(): void
    {
        $this->requireSodium();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('exactly 32 bytes');
        new CredentialService('not-a-valid-key');
    }

    public function testGeneratedKeyIsValid(): void
    {
        $this->requireSodium();
        self::assertInstanceOf(CredentialService::class, new CredentialService(CredentialService::generateKey()));
    }

    private function requireSodium(): void
    {
        if (!extension_loaded('sodium')) {
            self::markTestSkipped('libsodium is required for encryption-key tests.');
        }
    }
}

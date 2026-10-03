<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Configuration;

use PHPUnit\Framework\TestCase;
use TowerDNS\Application\Services\CredentialService;
use TowerDNS\Infrastructure\Configuration\AtomicConfigurationWriter;

/**
 * @psalm-api Runtime discovery by PHPUnit or local module loading is not statically visible.
 * @psalm-suppress PropertyNotSetInConstructor PHPUnit initializes the temporary directory in setUp().
 */
final class AtomicConfigurationWriterTest extends TestCase
{
    private string $directory;

    #[\Override]
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/towerdns-config-' . bin2hex(random_bytes(8));
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->globPaths($this->directory . '/*') as $file) {
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
        $permissions = fileperms($path);
        self::assertIsInt($permissions);
        self::assertSame(0o600, $permissions & 0o777);
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

    /** @return list<non-empty-string> */
    private function globPaths(string $pattern): array
    {
        $paths = glob($pattern);

        return $paths === false ? [] : array_map($this->nonEmptyPath(...), $paths);
    }

    /** @return non-empty-string */
    private function nonEmptyPath(string $path): string
    {
        if ($path === '') {
            throw new \UnexpectedValueException('glob returned an empty path.');
        }

        return $path;
    }
}

<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Backup;

use PHPUnit\Framework\TestCase;
use TowerDNS\Infrastructure\Backup\BackupException;
use TowerDNS\Infrastructure\Backup\BackupPayloadCipher;

/** @psalm-api Discovered by PHPUnit. */
final class BackupPayloadCipherTest extends TestCase
{
    public function testAuthenticatedStreamingHandlesExactChunkBoundariesAndRejectsTampering(): void
    {
        $directory = sys_get_temp_dir() . '/towerdns-cipher-' . bin2hex(random_bytes(8));
        mkdir($directory, 0o700);
        $passphrase = 'long-test-passphrase-2026';

        try {
            foreach ([1, 65_536, 131_072, 200_017] as $size) {
                $plain = random_bytes($size);
                file_put_contents($directory . '/plain', $plain);
                BackupPayloadCipher::encrypt($directory . '/plain', $directory . '/encrypted', $passphrase);
                BackupPayloadCipher::decrypt($directory . '/encrypted', $directory . '/restored', $passphrase);
                self::assertSame($plain, file_get_contents($directory . '/restored'));
                $permissions = fileperms($directory . '/encrypted');
                self::assertNotFalse($permissions);
                self::assertSame(0o600, $permissions & 0o777);
                unlink($directory . '/encrypted');
                unlink($directory . '/restored');
            }

            file_put_contents($directory . '/plain', random_bytes(65_536));
            BackupPayloadCipher::encrypt($directory . '/plain', $directory . '/encrypted', $passphrase);
            $bytes = (string) file_get_contents($directory . '/encrypted');
            file_put_contents($directory . '/encrypted', substr($bytes, 0, -3));
            try {
                BackupPayloadCipher::decrypt($directory . '/encrypted', $directory . '/failed', $passphrase);
                self::fail('A truncated authenticated stream must fail.');
            } catch (BackupException $exception) {
                self::assertStringContainsString('truncated', $exception->getMessage());
                self::assertFileDoesNotExist($directory . '/failed');
            }
        } finally {
            $files = glob($directory . '/*');
            foreach (is_array($files) ? $files : [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    public function testShortPassphraseIsRejected(): void
    {
        $directory = sys_get_temp_dir() . '/towerdns-cipher-' . bin2hex(random_bytes(8));
        mkdir($directory, 0o700);
        file_put_contents($directory . '/plain', 'payload');

        try {
            $this->expectException(BackupException::class);
            BackupPayloadCipher::encrypt($directory . '/plain', $directory . '/encrypted', 'too-short');
        } finally {
            $files = glob($directory . '/*');
            foreach (is_array($files) ? $files : [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}

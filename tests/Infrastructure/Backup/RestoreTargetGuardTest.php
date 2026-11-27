<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Backup;

use PHPUnit\Framework\TestCase;
use TowerDNS\Infrastructure\Backup\BackupException;
use TowerDNS\Infrastructure\Backup\RestoreTargetGuard;

/** @psalm-api Discovered by PHPUnit. */
final class RestoreTargetGuardTest extends TestCase
{
    public function testFailedTargetIsMarkedWithoutPersistingItsIdentityAndCannotBeReused(): void
    {
        $directory = sys_get_temp_dir() . '/towerdns-restore-guard-' . bin2hex(random_bytes(8));
        $identity  = 'mariadb' . "\0" . 'towerdns_restore_test' . "\0" . 'db.internal' . "\0" . '3306';
        $guard     = new RestoreTargetGuard($directory);

        try {
            $guard->assertClean($identity);
            $guard->markTainted($identity);
            self::assertDirectoryExists($directory);
            $directoryPermissions = fileperms($directory);
            self::assertNotFalse($directoryPermissions);
            self::assertSame(0o700, $directoryPermissions & 0o777);
            $files = glob($directory . '/*.json');
            $files = is_array($files) ? $files : [];
            self::assertCount(1, $files);
            $filePermissions = fileperms($files[0]);
            self::assertNotFalse($filePermissions);
            self::assertSame(0o600, $filePermissions & 0o777);
            self::assertStringNotContainsString('db.internal', (string) file_get_contents($files[0]));
            try {
                $guard->assertClean($identity);
                self::fail('A tainted restore target must not be reused.');
            } catch (BackupException $exception) {
                self::assertStringContainsString('tainted', $exception->getMessage());
            }
            $guard->assertClean($identity . '-new');
        } finally {
            $files = glob($directory . '/*');
            foreach (is_array($files) ? $files : [] as $file) {
                unlink($file);
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Configuration;

/** Writes private local configuration files atomically without exposing contents. */
final class AtomicConfigurationWriter
{
    public function write(string $path, string $contents): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0o750, true) && !is_dir($directory)) {
            throw new \RuntimeException('Configuration directory could not be created.');
        }
        if (!is_writable($directory)) {
            throw new \RuntimeException('Configuration directory is not writable.');
        }

        $temporary = $path . '.tmp.' . bin2hex(random_bytes(8));
        try {
            if (file_put_contents($temporary, $contents, LOCK_EX) === false || !chmod($temporary, 0o600)) {
                throw new \RuntimeException('Configuration file could not be written.');
            }
            if (!rename($temporary, $path)) {
                throw new \RuntimeException('Configuration file could not be activated.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}

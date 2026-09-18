<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Ui;

use PHPUnit\Framework\TestCase;

final class TranslationCatalogConsistencyTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../..';

    public function testCoreAndModuleCataloguesHaveMatchingKeysAndNoDuplicates(): void
    {
        $directories = [self::ROOT . '/translations'];
        foreach (glob(self::ROOT . '/modules/*/translations', GLOB_ONLYDIR) ?: [] as $directory) {
            $directories[] = $directory;
        }
        foreach ($directories as $directory) {
            $english = $this->catalogue($directory . '/en-GB.php');
            $german  = $this->catalogue($directory . '/de-DE.php');
            self::assertSame(array_keys($english), array_keys($german), $directory . ' key mismatch');
        }
    }

    public function testStaticallyUsedKeysExistInCatalogues(): void
    {
        $known = $this->catalogue(self::ROOT . '/translations/en-GB.php');
        foreach (glob(self::ROOT . '/modules/*/translations/en-GB.php') ?: [] as $file) {
            $known += $this->catalogue($file);
        }
        $missing = [];
        foreach ([self::ROOT . '/src', self::ROOT . '/themes/default/src'] as $directory) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));
            foreach ($files as $file) {
                if (!$file->isFile() || !in_array($file->getExtension(), ['php', 'svelte', 'ts'], true)) {
                    continue;
                }
                $source = (string) file_get_contents($file->getPathname());
                preg_match_all('/\b(?:translate|t|tp)\(\s*([\'\"])([a-z][a-z0-9_.-]+)\1/', $source, $matches);
                foreach ($matches[2] as $key) {
                    if (!array_key_exists($key, $known)) {
                        $missing[$key] = $file->getPathname();
                    }
                }
            }
        }
        self::assertSame([], $missing);
    }

    /** @return array<string, mixed> */
    private function catalogue(string $path): array
    {
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);
        preg_match_all('/^\s*\'([^\']*)\'\s*=>/m', $source, $matches);
        self::assertSame(count($matches[1]), count(array_unique($matches[1])), $path . ' duplicate keys');
        /** @var array<string, mixed> $catalogue */
        $catalogue = require $path;
        ksort($catalogue);
        return $catalogue;
    }
}

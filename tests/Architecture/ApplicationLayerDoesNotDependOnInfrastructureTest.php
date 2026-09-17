<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Guards the Application/Infrastructure layering invariant: Application
 * services must stay transport-neutral (usable from Web, CLI, and future
 * API adapters) and must never depend on concrete Infrastructure classes.
 *
 * Wiring/bootstrap files (ContainerFactory, Pipeline, Routes) are the only
 * allowed exception, since they exist specifically to connect the two
 * layers.
 */
final class ApplicationLayerDoesNotDependOnInfrastructureTest extends TestCase
{
    private const array ALLOWED_WIRING_FILES = [
        'ContainerFactory.php',
        'Pipeline.php',
        'Routes.php',
    ];

    public function testApplicationServicesDoNotImportInfrastructureNamespace(): void
    {
        $root       = dirname(__DIR__, 2) . '/src/Application';
        $violations = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php' || in_array($file->getFilename(), self::ALLOWED_WIRING_FILES, true)) {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            if ($contents === false) {
                continue;
            }

            if (preg_match('/^use\s+TowerDNS\\\\Infrastructure\\\\/m', $contents) === 1) {
                $violations[] = $file->getPathname();
            }
        }

        self::assertSame([], $violations, 'Application-layer files must not import TowerDNS\\Infrastructure classes: ' . implode(', ', $violations));
    }
}

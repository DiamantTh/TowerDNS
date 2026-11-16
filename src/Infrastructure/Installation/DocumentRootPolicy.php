<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Installation;

/** Keeps the application source and private runtime files outside the webroot. */
final class DocumentRootPolicy
{
    public static function matches(string $projectRoot, ?string $documentRoot): bool
    {
        if ($documentRoot === null || $documentRoot === '') {
            return false;
        }

        $expected = realpath(rtrim($projectRoot, '/\\') . '/httpdocs');
        $actual   = realpath($documentRoot);

        return $expected !== false && $actual !== false && $actual === $expected;
    }
}

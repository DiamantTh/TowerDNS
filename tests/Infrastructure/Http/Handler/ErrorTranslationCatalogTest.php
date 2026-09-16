<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Http\Handler;

use PHPUnit\Framework\TestCase;

final class ErrorTranslationCatalogTest extends TestCase
{
    /** @dataProvider localeProvider */
    public function testZoneRecordAndUserErrorKeysExistInBothRuntimeCatalogues(string $locale): void
    {
        /** @var array<string, string> $catalogue */
        $catalogue = require dirname(__DIR__, 4) . '/translations/' . $locale . '.php';

        foreach ([
            'http.error.invalid-request',
            'http.error.forbidden',
            'http.error.operation-failed',
            'zones.error.name-required',
            'zones.error.create-failed',
            'zones.error.delete-failed',
            'records.error.invalid-input',
            'records.error.unsupported-type',
            'records.error.create-failed',
            'records.error.update-failed',
            'records.error.delete-failed',
            'users.error.invalid-input',
            'users.error.create-failed',
            'users.error.delete-failed',
            'zone-members.error.grant-failed',
        ] as $key) {
            self::assertArrayHasKey($key, $catalogue);
            self::assertNotSame('', $catalogue[$key]);
        }
    }

    /** @return array<string, array{string}> */
    public static function localeProvider(): array
    {
        return [
            'German'  => ['de-DE'],
            'English' => ['en-GB'],
        ];
    }
}

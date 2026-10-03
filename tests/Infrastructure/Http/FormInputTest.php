<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\Http;

use PHPUnit\Framework\TestCase;
use TowerDNS\Infrastructure\Http\FormInput;

/** @psalm-api PHPUnit discovers this test case from its file at runtime. */
final class FormInputTest extends TestCase
{
    public function testNormalizesOnlyScalarFieldsFromUntrustedBody(): void
    {
        self::assertSame(
            ['csrf_token' => 'valid-token', 'enabled' => '1', 'attempts' => '3'],
            FormInput::fromParsedBody([
                'csrf_token' => 'valid-token',
                'enabled'    => true,
                'attempts'   => 3,
                'nested'     => ['attacker-controlled'],
                0            => 'numeric-key',
                'null'       => null,
            ]),
        );
    }

    public function testNonArrayBodiesAreRejected(): void
    {
        self::assertSame([], FormInput::fromParsedBody('not a form body'));
        self::assertSame(['token' => 'value'], FormInput::fromParsedBody((object) ['token' => 'value']));
    }
}

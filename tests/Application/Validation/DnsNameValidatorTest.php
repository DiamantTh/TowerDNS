<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Application\Validation;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use TowerDNS\Application\Validation\DnsNameValidator;

final class DnsNameValidatorTest extends TestCase
{
    public function testIdnGetsConverted(): void
    {
        self::assertSame('xn--mller-kva.eu', DnsNameValidator::normalise('Müller.eu'));
    }

    public function testTrailingDotIsStripped(): void
    {
        self::assertSame('example.com', DnsNameValidator::normalise('example.com.'));
    }

    public function testEmptyIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DnsNameValidator::normalise('');
    }
}

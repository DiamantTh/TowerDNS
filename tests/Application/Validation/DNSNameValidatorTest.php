<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Application\Validation;

use PHPUnit\Framework\TestCase;
use TowerDNS\Application\Validation\DNSNameValidator;

final class DnsNameValidatorTest extends TestCase
{
    public function testIdnGetsConverted(): void
    {
        self::assertSame('xn--mller-kva.eu', DNSNameValidator::normalise('Müller.eu'));
    }

    public function testTrailingDotIsStripped(): void
    {
        self::assertSame('example.com', DNSNameValidator::normalise('example.com.'));
    }

    public function testEmptyIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DNSNameValidator::normalise('');
    }
}

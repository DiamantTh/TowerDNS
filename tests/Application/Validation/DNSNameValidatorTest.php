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

    public function testRecordOwnersAreNormalizedRelativeToTheirZone(): void
    {
        self::assertSame('', DNSNameValidator::normaliseRecordOwner('@', 'Example.org.'));
        self::assertSame('', DNSNameValidator::normaliseRecordOwner('example.org.', 'example.org'));
        self::assertSame('www', DNSNameValidator::normaliseRecordOwner('WWW.Example.org.', 'example.org'));
        self::assertSame('_dmarc._domainkey', DNSNameValidator::normaliseRecordOwner('_dmarc._domainkey', 'example.org'));
    }

    public function testAbsoluteRecordOwnerOutsideItsZoneIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DNSNameValidator::normaliseRecordOwner('other.example.net.', 'example.org');
    }

    public function testMalformedRecordOwnerIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DNSNameValidator::normaliseRecordOwner('www..example', 'example.org');
    }

    public function testWildcardRecordOwnerMustBeTheFirstLabel(): void
    {
        self::assertSame('*.api', DNSNameValidator::normaliseRecordOwner('*.api', 'example.org'));

        $this->expectException(\InvalidArgumentException::class);
        DNSNameValidator::normaliseRecordOwner('www.*', 'example.org');
    }
}

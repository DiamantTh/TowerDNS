<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Domain\DNS;

use PHPUnit\Framework\TestCase;
use TowerDNS\Domain\DNS\DNSRecordType;

final class DnsRecordTypeTest extends TestCase
{
    public function testKnownTypesAreCanonicalized(): void
    {
        $type = DNSRecordType::parse('tlsa');
        self::assertSame('TLSA', $type->presentation);
        self::assertSame(52, $type->code);
        self::assertTrue($type->isKnown);
    }

    public function testUnknownRfc3597TypeIsPreserved(): void
    {
        $type = DNSRecordType::parse('type65400');
        self::assertSame('TYPE65400', $type->presentation);
        self::assertFalse($type->isKnown);
    }
}

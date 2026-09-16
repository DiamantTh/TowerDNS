<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Application\DNS;

use PHPUnit\Framework\TestCase;
use TowerDNS\Application\DNS\RdataCanonicalizer;
use TowerDNS\Domain\DNS\DNSRecordType;

final class RdataCanonicalizerTest extends TestCase
{
    public function testTlsaMatchingTypeZeroAcceptsLongAssociationData(): void
    {
        $data = str_repeat('ab', 4096);
        self::assertSame('3 0 0 ' . $data, RdataCanonicalizer::canonicalize(DNSRecordType::parse('TLSA'), '3 0 0 ' . strtoupper($data)));
    }

    public function testTxtPreservesMultipleCharacterStrings(): void
    {
        self::assertSame('"v=DKIM1" "k=rsa"', RdataCanonicalizer::canonicalize(DNSRecordType::parse('TXT'), '"v=DKIM1"  "k=rsa"'));
    }

    public function testUnknownTypeUsesRfc3597(): void
    {
        self::assertSame('\\# 3 aabbcc', RdataCanonicalizer::canonicalize(DNSRecordType::parse('TYPE65400'), '\\# 3 AABBCC'));
    }

    public function testInvalidTlsaIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RdataCanonicalizer::canonicalize(DNSRecordType::parse('TLSA'), '3 0 0 odd');
    }
}

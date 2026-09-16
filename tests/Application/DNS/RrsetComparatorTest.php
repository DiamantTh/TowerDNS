<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Application\DNS;

use PHPUnit\Framework\TestCase;
use TowerDNS\Application\DNS\RrsetComparator;
use TowerDNS\Domain\DNS\DNSRecordType;
use TowerDNS\Domain\DNS\Rrset;

final class RrsetComparatorTest extends TestCase
{
    public function testRdataOrderAndTlsaHexCaseDoNotMatter(): void
    {
        $type = DNSRecordType::parse('TLSA');
        $expected = new Rrset('example.org', '_443._tcp', $type, 300, ['3 1 1 AABB', '3 1 1 CCDD']);
        $observed = new Rrset('example.org', '_443._tcp.', $type, 300, ['3 1 1 ccdd', '3 1 1 aabb']);
        self::assertTrue(RrsetComparator::equals($expected, $observed));
    }

    public function testDifferentTtlIsNotVerified(): void
    {
        $type = DNSRecordType::parse('TXT');
        self::assertFalse(RrsetComparator::equals(
            new Rrset('example.org', '@', $type, 300, ['"one"']),
            new Rrset('example.org', '@', $type, 600, ['"one"']),
        ));
    }
}

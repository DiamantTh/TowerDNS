<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Infrastructure\DNS;

use PHPUnit\Framework\TestCase;
use TowerDNS\Infrastructure\DNS\DnsWireCodec;

/** @psalm-api Runtime discovery by PHPUnit is not statically visible. */
final class DnsWireCodecTest extends TestCase
{
    public function testBuildsNonRecursiveTlsaQuery(): void
    {
        $packet = DnsWireCodec::query('_443._tcp.example.org', 0x1234);
        $header = unpack('nid/nflags/nquestions', substr($packet, 0, 6));
        self::assertIsArray($header);
        self::assertSame(0x1234, $header['id']);
        self::assertSame(0, $header['flags'] & 0x0100, 'RD must remain clear for an authoritative-only query.');
        self::assertSame(1, $header['questions']);
        self::assertSame(pack('nn', 52, 1), substr($packet, -4));
    }

    public function testParsesCompressedAuthoritativeTlsaResponseAndCanonicalizesHex(): void
    {
        $question = "\x04_443\x04_tcp\x07example\x03org\0" . pack('nn', 52, 1);
        $rdata    = chr(3) . chr(1) . chr(1) . pack('H*', str_repeat('AB', 32));
        $answer   = "\xc0\x0c" . pack('nnNn', 52, 1, 360, strlen($rdata)) . $rdata;
        $packet   = pack('nnnnnn', 0x1234, 0x8400, 1, 1, 0, 0) . $question . $answer;

        $response = DnsWireCodec::parseTlsaResponse($packet, 0x1234, '_443._tcp.example.org');
        self::assertTrue($response->authoritative);
        self::assertSame(0, $response->rcode);
        self::assertSame(360, $response->minimumTtl);
        self::assertSame(['3 1 1 ' . str_repeat('ab', 32)], $response->tlsaRecords);
    }

    public function testDoesNotCountTlsaRecordsOutsideTheRequestedAnswerRrset(): void
    {
        $question  = "\x04_443\x04_tcp\x07example\x03org\0" . pack('nn', 52, 1);
        $rdata     = chr(3) . chr(1) . chr(1) . pack('H*', str_repeat('cd', 32));
        $authority = "\xc0\x0c" . pack('nnNn', 52, 1, 300, strlen($rdata)) . $rdata;
        $packet    = pack('nnnnnn', 0x1234, 0x8400, 1, 0, 1, 0) . $question . $authority;

        $response = DnsWireCodec::parseTlsaResponse($packet, 0x1234, '_443._tcp.example.org');
        self::assertSame([], $response->tlsaRecords);
        self::assertNull($response->minimumTtl);
    }

    public function testRejectsInvalidCompressionPointer(): void
    {
        $loopingQuestion = "\xc0\x0c" . pack('nn', 52, 1);
        $packet          = pack('nnnnnn', 0x1234, 0x8000, 1, 0, 0, 0) . $loopingQuestion;
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('DNS compression pointer is invalid.');
        DnsWireCodec::parseTlsaResponse($packet, 0x1234, '_443._tcp.example.org');
    }

    public function testRejectsMismatchedTransaction(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('DNS response header is invalid.');
        DnsWireCodec::parseTlsaResponse(pack('nnnnnn', 0x1235, 0x8000, 0, 0, 0, 0), 0x1234, '_443._tcp.example.org');
    }
}

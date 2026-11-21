<?php

declare(strict_types=1);

namespace TowerDNS\Infrastructure\DNS;

use TowerDNS\Application\DNS\RdataCanonicalizer;
use TowerDNS\Domain\DNS\DNSRecordType;

/** Minimal bounded DNS wire codec for direct authoritative TLSA queries (RFC 1035). */
final class DnsWireCodec
{
    /** Builds a bounded recursive DNSSEC query with the DNSSEC OK bit set. */
    public static function dnssecQuery(string $name, int $id, int $type): string
    {
        if (!in_array($type, [15, 43, 52], true)) {
            throw new \InvalidArgumentException('Unsupported DNS diagnostic query type.');
        }

        $wireName = self::wireName($name);

        return pack('nnnnnn', $id & 0xffff, 0x0100, 1, 0, 0, 1)
            . $wireName . pack('nn', $type, 1)
            . "\0" . pack('nnNn', 41, 1232, 0x00008000, 0);
    }

    /** Parses the bounded DNSSEC-relevant response fields used by diagnostics. */
    public static function parseDnssecResponse(string $packet, int $expectedId, string $expectedName, int $expectedType): DnssecWireResponse
    {
        if (strlen($packet) < 12 || strlen($packet) > 65535) {
            throw new \UnexpectedValueException('DNS response length is invalid.');
        }
        $header = unpack('nid/nflags/nquestions/nanswers/nauthority/nadditional', substr($packet, 0, 12));
        if (!is_array($header) || $header['id']               !== ($expectedId & 0xffff) || ($header['flags'] & 0x8000) === 0
                               || ($header['flags'] & 0x7800) !== 0 || $header['questions'] !== 1) {
            throw new \UnexpectedValueException('DNS response header is invalid.');
        }
        $offset   = 12;
        $question = self::readName($packet, $offset);
        if ($offset + 4 > strlen($packet)) {
            throw new \UnexpectedValueException('DNS question is truncated.');
        }
        $questionFields = unpack('ntype/nclass', substr($packet, $offset, 4));
        $offset += 4;
        if (!is_array($questionFields) || $question               !== strtolower(rtrim($expectedName, '.'))
                                       || $questionFields['type'] !== $expectedType || $questionFields['class'] !== 1) {
            throw new \UnexpectedValueException('DNS response question does not match the request.');
        }

        $total = $header['answers'] + $header['authority'] + $header['additional'];
        if ($total > 512) {
            throw new \UnexpectedValueException('DNS response contains too many records.');
        }
        $answers  = [];
        $edeCodes = [];
        for ($index = 0; $index < $total; ++$index) {
            $section = $index < $header['answers'] ? 'answer' : ($index < $header['answers'] + $header['authority'] ? 'authority' : 'additional');
            $owner   = self::readName($packet, $offset);
            if ($offset + 10 > strlen($packet)) {
                throw new \UnexpectedValueException('DNS resource record is truncated.');
            }
            $rr = unpack('ntype/nclass/Nttl/nlength', substr($packet, $offset, 10));
            $offset += 10;
            if (!is_array($rr) || $offset + $rr['length'] > strlen($packet)) {
                throw new \UnexpectedValueException('DNS resource data is truncated.');
            }
            $rdata = substr($packet, $offset, $rr['length']);
            $offset += $rr['length'];
            if ($rr['type'] === 41) {
                $optionOffset = 0;
                while ($optionOffset < strlen($rdata)) {
                    if ($optionOffset + 4 > strlen($rdata)) {
                        throw new \UnexpectedValueException('EDNS option is truncated.');
                    }
                    $option = unpack('ncode/nlength', substr($rdata, $optionOffset, 4));
                    $optionOffset += 4;
                    if (!is_array($option) || $optionOffset + $option['length'] > strlen($rdata)) {
                        throw new \UnexpectedValueException('EDNS option length is invalid.');
                    }
                    if ($option['code'] === 15 && $option['length'] >= 2) {
                        $info = unpack('ncode', substr($rdata, $optionOffset, 2));
                        if (!is_array($info)) {
                            throw new \UnexpectedValueException('EDNS Extended DNS Error is malformed.');
                        }
                        $edeCodes[] = $info['code'];
                    }
                    $optionOffset += $option['length'];
                }
            } elseif ($section === 'answer') {
                $answers[] = ['owner' => $owner, 'type' => $rr['type'], 'class' => $rr['class'], 'ttl' => $rr['ttl'], 'rdata' => $rdata, 'offset' => $offset - $rr['length']];
            }
        }

        return new DnssecWireResponse(
            $header['flags'] & 0x000f,
            (bool) ($header['flags'] & 0x0020),
            $answers,
            array_values(array_unique($edeCodes)),
            $packet,
        );
    }

    public static function query(string $name, int $id): string
    {
        $name = rtrim($name, '.');
        if ($name === '' || strlen($name) > 253) {
            throw new \InvalidArgumentException('Invalid DNS query name.');
        }
        if (preg_match('/[^\x00-\x7f]/', $name) === 1) {
            if (!function_exists('idn_to_ascii')) {
                throw new \InvalidArgumentException('Internationalized DNS names are unavailable.');
            }
            $ascii = idn_to_ascii($name, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (!is_string($ascii)) {
                throw new \InvalidArgumentException('Invalid internationalized DNS name.');
            }
            $name = $ascii;
        }

        $wireName = '';
        foreach (explode('.', $name) as $label) {
            if ($label === '' || strlen($label) > 63 || preg_match('/^[a-z0-9_-]+$/iD', $label) !== 1) {
                throw new \InvalidArgumentException('Invalid DNS query label.');
            }
            $wireName .= chr(strlen($label)) . $label;
        }

        return pack('nnnnnn', $id & 0xffff, 0, 1, 0, 0, 0)
            . $wireName . "\0" . pack('nn', 52, 1);
    }

    public static function parseTlsaResponse(string $packet, int $expectedId, string $expectedName): DnsWireResponse
    {
        if (strlen($packet) < 12) {
            throw new \UnexpectedValueException('DNS response header is truncated.');
        }
        $header = unpack('nid/nflags/nquestions/nanswers/nauthority/nadditional', substr($packet, 0, 12));
        if (!is_array($header) || $header['id'] !== ($expectedId & 0xffff) || ($header['flags'] & 0x8000) === 0 || ($header['flags'] & 0x7800) !== 0 || $header['questions'] !== 1) {
            throw new \UnexpectedValueException('DNS response header is invalid.');
        }
        if (($header['flags'] & 0x0200) !== 0) {
            return new DnsWireResponse($header['flags'] & 0x000f, (bool) ($header['flags'] & 0x0400), true, [], null);
        }
        $offset       = 12;
        $questionName = self::readName($packet, $offset);
        if ($offset + 4 > strlen($packet)) {
            throw new \UnexpectedValueException('DNS question is truncated.');
        }
        $question = unpack('ntype/nclass', substr($packet, $offset, 4));
        $offset += 4;
        if (!is_array($question) || $question['type'] !== 52 || $question['class'] !== 1 || $questionName !== strtolower(rtrim($expectedName, '.'))) {
            throw new \UnexpectedValueException('DNS response question does not match the TLSA query.');
        }

        $records = [];
        $ttls    = [];
        $total   = $header['answers'] + $header['authority'] + $header['additional'];
        if ($total > 512) {
            throw new \UnexpectedValueException('DNS response contains too many records.');
        }
        $expectedOwner = strtolower(rtrim($expectedName, '.'));
        for ($i = 0; $i < $total; ++$i) {
            $recordOwner = self::readName($packet, $offset);
            if ($offset + 10 > strlen($packet)) {
                throw new \UnexpectedValueException('DNS resource record is truncated.');
            }
            $rr = unpack('ntype/nclass/Nttl/nlength', substr($packet, $offset, 10));
            $offset += 10;
            if (!is_array($rr) || $offset + $rr['length'] > strlen($packet)) {
                throw new \UnexpectedValueException('DNS resource data is truncated.');
            }
            $rdata = substr($packet, $offset, $rr['length']);
            $offset += $rr['length'];
            // Only answer-section records at the requested owner describe this RRset.
            // Authority/additional TLSA data must not accidentally satisfy the check.
            if ($i < $header['answers'] && $recordOwner === $expectedOwner && $rr['type'] === 52 && $rr['class'] === 1) {
                if (strlen($rdata) < 4) {
                    throw new \UnexpectedValueException('TLSA resource data is malformed.');
                }
                $records[] = RdataCanonicalizer::canonicalize(
                    DNSRecordType::parse('TLSA'),
                    ord($rdata[0]) . ' ' . ord($rdata[1]) . ' ' . ord($rdata[2]) . ' ' . bin2hex(substr($rdata, 3)),
                );
                $ttls[] = $rr['ttl'];
            }
        }

        $records = array_values(array_unique($records));
        sort($records, SORT_STRING);
        return new DnsWireResponse($header['flags'] & 0x000f, (bool) ($header['flags'] & 0x0400), (bool) ($header['flags'] & 0x0200), $records, $ttls === [] ? null : min($ttls));
    }

    /** @return array{preference: int, exchange: string} */
    public static function parseMxRdata(string $packet, int $rdataOffset, int $rdataLength): array
    {
        if ($rdataLength < 3 || $rdataOffset + $rdataLength > strlen($packet)) {
            throw new \UnexpectedValueException('MX resource data is malformed.');
        }
        $fields = unpack('npreference', substr($packet, $rdataOffset, 2));
        if (!is_array($fields)) {
            throw new \UnexpectedValueException('MX preference is malformed.');
        }
        $preference = $fields['preference'];
        $nameOffset = $rdataOffset + 2;
        $exchange   = self::readName($packet, $nameOffset);
        if ($nameOffset !== $rdataOffset + $rdataLength) {
            throw new \UnexpectedValueException('MX exchange name length is invalid.');
        }

        return ['preference' => $preference, 'exchange' => $exchange];
    }

    private static function readName(string $packet, int &$offset): string
    {
        $cursor   = $offset;
        $jumped   = false;
        $visited  = [];
        $labels   = [];
        $expanded = 0;
        while (true) {
            if ($cursor >= strlen($packet)) {
                throw new \UnexpectedValueException('DNS name is truncated.');
            }
            $length = ord($packet[$cursor]);
            if (($length & 0xc0) === 0xc0) {
                if ($cursor + 1 >= strlen($packet)) {
                    throw new \UnexpectedValueException('DNS compression pointer is truncated.');
                }
                $pointer = (($length & 0x3f) << 8) | ord($packet[$cursor + 1]);
                if ($pointer >= strlen($packet) || isset($visited[$pointer]) || count($visited) > 32) {
                    throw new \UnexpectedValueException('DNS compression pointer is invalid.');
                }
                $visited[$pointer] = true;
                if (!$jumped) {
                    $offset = $cursor + 2;
                    $jumped = true;
                }
                $cursor = $pointer;
                continue;
            }
            if (($length & 0xc0) !== 0 || $length > 63) {
                throw new \UnexpectedValueException('DNS label length is invalid.');
            }
            ++$cursor;
            if ($length === 0) {
                if (!$jumped) {
                    $offset = $cursor;
                }
                break;
            }
            if ($cursor + $length > strlen($packet)) {
                throw new \UnexpectedValueException('DNS label is truncated.');
            }
            $labels[] = strtolower(substr($packet, $cursor, $length));
            $cursor   += $length;
            $expanded += $length + 1;
            if ($expanded > 255 || count($labels) > 127) {
                throw new \UnexpectedValueException('DNS name exceeds protocol limits.');
            }
        }
        return implode('.', $labels);
    }

    private static function wireName(string $name): string
    {
        $name = rtrim($name, '.');
        if ($name === '' || strlen($name) > 253) {
            throw new \InvalidArgumentException('Invalid DNS query name.');
        }
        if (preg_match('/[^\x00-\x7f]/', $name) === 1) {
            if (!function_exists('idn_to_ascii')) {
                throw new \InvalidArgumentException('Internationalized DNS names are unavailable.');
            }
            $ascii = idn_to_ascii($name, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (!is_string($ascii)) {
                throw new \InvalidArgumentException('Invalid internationalized DNS name.');
            }
            $name = $ascii;
        }

        $wireName = '';
        foreach (explode('.', $name) as $label) {
            if ($label === '' || strlen($label) > 63 || preg_match('/^[a-z0-9_-]+$/iD', $label) !== 1) {
                throw new \InvalidArgumentException('Invalid DNS query label.');
            }
            $wireName .= chr(strlen($label)) . $label;
        }

        return $wireName . "\0";
    }
}

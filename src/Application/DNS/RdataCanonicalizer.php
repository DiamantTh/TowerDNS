<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\DNS;

use TowerDNS\Domain\DNS\DNSRecordType;

/**
 * Validates the common DNS presentation formats and produces comparison keys.
 * Unsupported mnemonic types remain opaque; RFC 3597 is always validated.
 */
final class RdataCanonicalizer
{
    public static function canonicalize(DNSRecordType $type, string $rdata): string
    {
        $rdata = trim($rdata);
        if ($rdata === '') {
            throw new \InvalidArgumentException('RDATA darf nicht leer sein.');
        }

        return match ($type->presentation) {
            'A'             => self::ip($rdata, FILTER_FLAG_IPV4, 'A-Record erwartet eine IPv4-Adresse.'),
            'AAAA'          => self::ip($rdata, FILTER_FLAG_IPV6, 'AAAA-Record erwartet eine IPv6-Adresse.'),
            'TLSA'          => self::tlsa($rdata),
            'TXT'           => self::txt($rdata),
            'CAA'           => self::caa($rdata),
            'DS'            => self::ds($rdata),
            'DNSKEY'        => self::dnskey($rdata),
            'SSHFP'         => self::sshfp($rdata),
            'MX'            => self::numericPrefix($rdata, 1, [65535]),
            'SRV'           => self::numericPrefix($rdata, 3, [65535, 65535, 65535]),
            'SVCB', 'HTTPS' => self::svcb($rdata),
            default         => $type->isKnown ? $rdata : self::rfc3597($rdata),
        };
    }

    private static function ip(string $value, int $flag, string $message): string
    {
        if (!filter_var($value, FILTER_VALIDATE_IP, $flag)) {
            throw new \InvalidArgumentException($message);
        }
        $packed = inet_pton($value);
        if ($packed === false) {
            throw new \InvalidArgumentException($message);
        }
        return strtolower((string) inet_ntop($packed));
    }

    private static function tlsa(string $value): string
    {
        $parts = preg_split('/\s+/', $value, 4);
        if ($parts === false || count($parts) !== 4
                             || !ctype_digit($parts[0]) || !ctype_digit($parts[1]) || !ctype_digit($parts[2])) {
            throw new \InvalidArgumentException('TLSA erwartet Usage, Selector, Matching Type und Association Data.');
        }
        [$usage, $selector, $matching] = array_map(intval(...), array_slice($parts, 0, 3));
        $data                          = strtolower(preg_replace('/\s+/', '', $parts[3]) ?? '');
        if ($usage > 3 || $selector > 1 || $matching > 2 || $data === '' || strlen($data) % 2 !== 0 || !ctype_xdigit($data)) {
            throw new \InvalidArgumentException('Ungültiger TLSA-RDATA-Wert.');
        }
        return "{$usage} {$selector} {$matching} {$data}";
    }

    private static function txt(string $value): string
    {
        preg_match_all('/"((?:\\\\.|[^"\\\\])*)"/s', $value, $matches, PREG_OFFSET_CAPTURE);
        $strings = $matches[0];
        if ($strings === [] || trim(preg_replace('/"(?:\\\\.|[^"\\\\])*"/s', '', $value) ?? '') !== '') {
            throw new \InvalidArgumentException('TXT erwartet einen oder mehrere quotierte Character-Strings.');
        }
        foreach ($matches[1] as $match) {
            $decoded = preg_replace_callback('/\\\\([0-9]{3}|.)/', static fn(array $m): string => ctype_digit($m[1]) ? chr((int) $m[1]) : $m[1], $match[0]);
            if (!is_string($decoded) || strlen($decoded) > 255) {
                throw new \InvalidArgumentException('Jeder TXT Character-String darf höchstens 255 Oktette enthalten.');
            }
        }
        return preg_replace('/\s+/', ' ', trim($value)) ?? $value;
    }

    private static function caa(string $value): string
    {
        if (preg_match('/^(0|[1-9][0-9]{0,2})\s+([A-Za-z0-9-]{1,15})\s+"((?:\\\\.|[^"\\\\])*)"$/sD', $value, $m) !== 1 || (int) $m[1] > 255) {
            throw new \InvalidArgumentException('CAA erwartet Flag, Tag und einen quotierten Wert.');
        }
        return (int) $m[1] . ' ' . strtolower($m[2]) . ' "' . $m[3] . '"';
    }

    private static function ds(string $value): string
    {
        $parts = preg_split('/\s+/', $value, 4);
        if ($parts === false || count($parts) !== 4 || !ctype_digit($parts[0]) || !ctype_digit($parts[1]) || !ctype_digit($parts[2])
                             || (int) $parts[0] > 65535 || (int) $parts[1] > 255 || (int) $parts[2] > 255
                             || strlen($parts[3]) % 2 !== 0 || !ctype_xdigit($parts[3])) {
            throw new \InvalidArgumentException('Ungültiger DS-RDATA-Wert.');
        }
        return (int) $parts[0] . ' ' . (int) $parts[1] . ' ' . (int) $parts[2] . ' ' . strtolower($parts[3]);
    }

    private static function dnskey(string $value): string
    {
        $parts = preg_split('/\s+/', $value, 4);
        if (
            $parts === false
            || count($parts) !== 4
            || !ctype_digit($parts[0])
            || $parts[1] !== '3'
            || !ctype_digit($parts[2])
            || (int) $parts[0] > 65535
            || (int) $parts[2] > 255
            || base64_decode($parts[3], true) === false
        ) {
            throw new \InvalidArgumentException('Ungültiger DNSKEY-RDATA-Wert.');
        }
        return (int) $parts[0] . ' 3 ' . (int) $parts[2] . ' ' . $parts[3];
    }

    private static function sshfp(string $value): string
    {
        $parts = preg_split('/\s+/', $value, 3);
        if ($parts === false || count($parts) !== 3 || !ctype_digit($parts[0]) || !ctype_digit($parts[1]) || (int) $parts[0] > 255 || (int) $parts[1] > 255 || strlen($parts[2]) % 2 !== 0 || !ctype_xdigit($parts[2])) {
            throw new \InvalidArgumentException('Ungültiger SSHFP-RDATA-Wert.');
        }
        return (int) $parts[0] . ' ' . (int) $parts[1] . ' ' . strtolower($parts[2]);
    }

    /** @param list<int> $limits */
    private static function numericPrefix(string $value, int $count, array $limits): string
    {
        $parts = preg_split('/\s+/', $value, $count + 1);
        if ($parts === false || count($parts) !== $count + 1) {
            throw new \InvalidArgumentException('Ungültiger strukturierter DNS-RDATA-Wert.');
        }
        foreach (array_slice($parts, 0, $count) as $i => $part) {
            if (!ctype_digit($part) || (int) $part > $limits[$i]) {
                throw new \InvalidArgumentException('Ungültiger numerischer DNS-RDATA-Wert.');
            }
            $parts[$i] = (string) (int) $part;
        }
        return implode(' ', $parts);
    }

    private static function svcb(string $value): string
    {
        $parts = preg_split('/\s+/', $value, 3);
        if ($parts === false || count($parts) < 2 || !ctype_digit($parts[0]) || (int) $parts[0] > 65535) {
            throw new \InvalidArgumentException('SVCB/HTTPS erwartet Priority und TargetName.');
        }
        return (int) $parts[0] . ' ' . strtolower(rtrim($parts[1], '.')) . (isset($parts[2]) ? ' ' . $parts[2] : '');
    }

    private static function rfc3597(string $value): string
    {
        if (preg_match('/^\\\\#\s+([0-9]{1,5})\s+([0-9A-Fa-f]+)$/D', $value, $m) !== 1 || strlen($m[2]) % 2 !== 0 || !ctype_xdigit($m[2]) || (int) $m[1] !== intdiv(strlen($m[2]), 2)) {
            throw new \InvalidArgumentException('Unbekannte RR-Typen erwarten RFC-3597-Notation: \\# <length> <hex>.');
        }
        return '\\# ' . (int) $m[1] . ' ' . strtolower($m[2]);
    }
}

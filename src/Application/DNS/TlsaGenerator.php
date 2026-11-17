<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\DNS;

/**
 * Offline TLSA generator (RFC 6698 and RFC 7671).
 *
 * Operates on public X.509 certificates or SubjectPublicKeyInfo PEM blocks.
 * Deliberately does not probe remote TLS services or invoke shell commands.
 * The DER parser selects SPKI by ASN.1 position, not by RSA/ECDSA/EdDSA type.
 */
final class TlsaGenerator
{
    /**
     * @return list<string> Canonical TLSA presentation RDATA.
     */
    public function generate(string $pem, int $usage, int $selector, int $matchingType): array
    {
        if ($usage < 0 || $usage > 3 || !in_array($selector, [0, 1], true) || !in_array($matchingType, [0, 1, 2], true)) {
            throw new \InvalidArgumentException('Unsupported TLSA usage, selector or matching type.');
        }
        if ($pem === '' || strlen($pem) > 750_000 || preg_match('/-----BEGIN [^-]*PRIVATE KEY-----/', $pem)) {
            throw new \InvalidArgumentException('Provide only public certificates or public keys (at most 750 KB).');
        }

        $pattern = '/-----BEGIN (CERTIFICATE|PUBLIC KEY)-----([A-Za-z0-9+\/=\s]+)-----END \1-----/';
        if (preg_match_all($pattern, $pem, $matches, PREG_SET_ORDER) < 1 || count($matches) > 32) {
            throw new \InvalidArgumentException('Expected 1-32 PEM CERTIFICATE or PUBLIC KEY blocks.');
        }
        if (trim((string) preg_replace($pattern, '', $pem)) !== '') {
            throw new \InvalidArgumentException('Unrecognized data outside PEM blocks.');
        }

        $result = [];
        foreach ($matches as $match) {
            $base64 = preg_replace('/\s+/', '', $match[2]);
            $der = base64_decode((string) $base64, true);
            if (!is_string($der) || $der === '' || base64_encode($der) !== $base64) {
                throw new \InvalidArgumentException('Malformed PEM base64 data.');
            }
            $selected = $match[1] === 'CERTIFICATE'
                ? ($selector === 0 ? self::assertCertificate($der) : self::certificateSpki($der))
                : ($selector === 1 ? self::assertSpki($der) : throw new \InvalidArgumentException('Selector 0 needs a complete certificate.'));

            $association = match ($matchingType) {
                0 => bin2hex($selected),
                1 => hash('sha256', $selected),
                2 => hash('sha512', $selected),
            };
            // DNS wire RDATA has a 65535-octet maximum, including the three TLSA parameter octets.
            if (strlen($selected) > 65_532 && $matchingType === 0) {
                throw new \InvalidArgumentException('Unhashed TLSA association data exceeds the DNS RDATA limit.');
            }
            $result[] = "{$usage} {$selector} {$matchingType} {$association}";
        }
        return array_values(array_unique($result));
    }

    private static function assertCertificate(string $der): string
    {
        self::certificateSpki($der);
        return $der;
    }

    private static function certificateSpki(string $der): string
    {
        $outer = self::node($der, 0);
        if ($outer[0] !== 0x30 || $outer[3] !== strlen($der)) {
            throw new \InvalidArgumentException('Expected a DER X.509 certificate.');
        }
        $tbs = self::node($der, $outer[2]);
        if ($tbs[0] !== 0x30) {
            throw new \InvalidArgumentException('Invalid TBSCertificate.');
        }
        $cursor = $tbs[2];
        $first = self::node($der, $cursor);
        if ($first[0] === 0xa0) { // optional explicit version
            $cursor = $first[3];
            $first = self::node($der, $cursor);
        }
        if ($first[0] !== 0x02) { // serialNumber
            throw new \InvalidArgumentException('Invalid X.509 serial number.');
        }
        $cursor = $first[3];
        // signature, issuer, validity and subject precede subjectPublicKeyInfo.
        foreach ([0x30, 0x30, 0x30, 0x30] as $expected) {
            $field = self::node($der, $cursor);
            if ($field[0] !== $expected || $field[3] > $tbs[3]) {
                throw new \InvalidArgumentException('Invalid X.509 certificate fields.');
            }
            $cursor = $field[3];
        }
        $spki = self::node($der, $cursor);
        if ($spki[0] !== 0x30 || $spki[3] > $tbs[3]) {
            throw new \InvalidArgumentException('Missing X.509 SubjectPublicKeyInfo.');
        }
        $selected = substr($der, $spki[1], $spki[3] - $spki[1]);
        return self::assertSpki($selected);
    }

    private static function assertSpki(string $der): string
    {
        $outer = self::node($der, 0);
        if ($outer[0] !== 0x30 || $outer[3] !== strlen($der)) {
            throw new \InvalidArgumentException('Expected DER SubjectPublicKeyInfo.');
        }
        $algorithm = self::node($der, $outer[2]);
        $bits = self::node($der, $algorithm[3]);
        if ($algorithm[0] !== 0x30 || $bits[0] !== 0x03 || $bits[3] !== $outer[3] || $bits[3] <= $bits[2] + 1
            || ord($der[$bits[2]]) > 7) {
            throw new \InvalidArgumentException('Malformed SubjectPublicKeyInfo.');
        }
        return $der;
    }

    /**
     * @return array{int, int, int, int} tag, start, content start, end.
     */
    private static function node(string $der, int $offset): array
    {
        $total = strlen($der);
        if ($offset < 0 || $offset + 2 > $total) {
            throw new \InvalidArgumentException('Truncated DER element.');
        }
        $start = $offset;
        $tag = ord($der[$offset++]);
        $length = ord($der[$offset++]);
        if (($length & 0x80) !== 0) {
            $bytes = $length & 0x7f;
            if ($bytes === 0 || $bytes > 4 || $offset + $bytes > $total || ord($der[$offset]) === 0) {
                throw new \InvalidArgumentException('Unsupported DER length.');
            }
            $length = 0;
            for ($i = 0; $i < $bytes; ++$i) {
                $length = ($length << 8) | ord($der[$offset++]);
            }
            if ($length < 128) {
                throw new \InvalidArgumentException('Non-canonical DER length.');
            }
        }
        if ($length > $total - $offset) {
            throw new \InvalidArgumentException('DER value exceeds its container.');
        }
        return [$tag, $start, $offset, $offset + $length];
    }
}

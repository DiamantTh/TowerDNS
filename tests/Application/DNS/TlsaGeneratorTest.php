<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Tests\Application\DNS;

use PHPUnit\Framework\TestCase;
use TowerDNS\Application\DNS\TlsaGenerator;

/** @psalm-api PHPUnit runtime discovery. */
final class TlsaGeneratorTest extends TestCase
{
    /** @return array{string, string} Public certificate PEM and public SPKI DER. */
    private static function certificate(): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        self::assertNotFalse($key);
        $csr = openssl_csr_new(['commonName' => 'mail.example.org'], $key);
        self::assertNotFalse($csr);
        $certificate = openssl_csr_sign($csr, null, $key, 1);
        self::assertNotFalse($certificate);
        self::assertTrue(openssl_x509_export($certificate, $pem));
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        $publicDer = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $details['key']) ?: '', true);
        self::assertIsString($publicDer);
        return [$pem, $publicDer];
    }

    public function testCertificateSpkiSha256(): void
    {
        [$certificate, $spki] = self::certificate();
        $result = (new TlsaGenerator())->generate($certificate, 3, 1, 1);
        self::assertSame(['3 1 1 ' . hash('sha256', $spki)], $result);
    }

    public function testAllStandardSelectorsAndMatchingTypes(): void
    {
        [$certificate, $spki] = self::certificate();
        $certificateDer = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $certificate) ?: '', true);
        self::assertIsString($certificateDer);
        foreach ([0, 1, 2, 3] as $usage) {
            foreach ([0, 1] as $selector) {
                foreach ([0, 1, 2] as $matching) {
                    $selected = $selector === 0 ? $certificateDer : $spki;
                    $expected = match ($matching) {
                        0 => bin2hex($selected),
                        1 => hash('sha256', $selected),
                        2 => hash('sha512', $selected),
                    };
                    self::assertSame(["{$usage} {$selector} {$matching} {$expected}"], (new TlsaGenerator())->generate($certificate, $usage, $selector, $matching));
                }
            }
        }
    }

    public function testMultipleCertificatesAreIndependentAndDuplicatesCollapse(): void
    {
        [$first] = self::certificate();
        [$second] = self::certificate();
        $records = (new TlsaGenerator())->generate($first . "\n" . $second . "\n" . $first, 3, 1, 1);
        self::assertCount(2, $records);
        self::assertNotSame($records[0], $records[1]);
    }

    public function testSpkiPublicKeyAllowedWithoutCertificate(): void
    {
        [, $spki] = self::certificate();
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----";
        self::assertSame(['2 1 2 ' . hash('sha512', $spki)], (new TlsaGenerator())->generate($pem, 2, 1, 2));
        $this->expectException(\InvalidArgumentException::class);
        (new TlsaGenerator())->generate($pem, 2, 0, 1);
    }

    public function testRejectsPrivateKeysAndUnsupportedParameters(): void
    {
        $generator = new TlsaGenerator();
        try {
            $generator->generate("-----BEGIN PRIVATE KEY-----\nAQ==\n-----END PRIVATE KEY-----", 3, 1, 1);
            self::fail('Private key accepted.');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }
        $this->expectException(\InvalidArgumentException::class);
        $generator->generate('anything', 3, 1, 9);
    }

    public function testRejectsTruncatedCertificateDer(): void
    {
        $pem = "-----BEGIN CERTIFICATE-----\n" . base64_encode("\x30\x10\x01\x01\x00") . "\n-----END CERTIFICATE-----";
        $this->expectException(\InvalidArgumentException::class);
        (new TlsaGenerator())->generate($pem, 3, 1, 1);
    }
}

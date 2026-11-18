<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Application\DNS;

use PHPUnit\Framework\TestCase;
use TowerDNS\Application\DNS\TlsaGenerator;

final class TlsaGeneratorTest extends TestCase
{
    public function testAllCombinationsAndDeduplication(): void
    {
        [$pem, $der, $spki]       = $this->certificate('service.example.test', false);
        [$caPem, $caDer, $caSpki] = $this->certificate('issuer.example.test', true);
        $generator                = new TlsaGenerator();
        for ($usage = 0; $usage <= 3; ++$usage) {
            for ($selector = 0; $selector <= 1; ++$selector) {
                for ($matching = 0; $matching <= 2; ++$matching) {
                    $caUsage        = in_array($usage, [0, 2], true);
                    $certificatePem = $caUsage ? $caPem : $pem;
                    $bytes          = $selector === 0 ? ($caUsage ? $caDer : $der) : ($caUsage ? $caSpki : $spki);
                    $expected       = match ($matching) {
                        0 => bin2hex($bytes), 1 => hash('sha256', $bytes), 2 => hash('sha512', $bytes),
                    };
                    self::assertSame(["{$usage} {$selector} {$matching} {$expected}"], $generator->generate($certificatePem . $certificatePem, $usage, $selector, $matching));
                    self::assertSame(["{$usage} {$selector} {$matching} {$expected}"], $generator->generate($caUsage ? $caDer : $der, $usage, $selector, $matching));
                }
            }
        }
    }

    public function testChainSelectsCaForTaAndEndEntityForEeUsages(): void
    {
        [$leaf]    = $this->certificate('service.example.test', false);
        [$ca]      = $this->certificate('issuer.example.test', true);
        $generator = new TlsaGenerator();
        $chain     = $leaf . $ca;
        self::assertCount(2, $generator->inspect($chain));
        self::assertStringStartsWith('0 ', $generator->generate($chain, 0, 1, 1)[0]);
        self::assertStringStartsWith('1 ', $generator->generate($chain, 1, 1, 1)[0]);
        self::assertStringStartsWith('2 ', $generator->generate($chain, 2, 1, 1)[0]);
        self::assertStringStartsWith('3 ', $generator->generate($chain, 3, 1, 1)[0]);
        self::assertNotSame($generator->generate($chain, 0, 1, 1), $generator->generate($chain, 1, 1, 1));
    }

    /** @return array{string, string, string} PEM, DER, SPKI DER */
    private function certificate(string $name, bool $ca): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        $csr = openssl_csr_new(['commonName' => $name], $key, ['digest_alg' => 'sha256']);
        self::assertNotFalse($csr);
        $options = ['digest_alg' => 'sha256', 'x509_extensions' => 'usr_cert'];
        if ($ca) {
            $options['x509_extensions'] = 'v3_ca';
        }
        $cert = openssl_csr_sign($csr, null, $key, 1, $options);
        self::assertNotFalse($cert);
        self::assertTrue(openssl_x509_export($cert, $pem));
        $der     = base64_decode((string) preg_replace('/-----[^-]+-----|\s+/', '', $pem), true);
        $details = openssl_pkey_get_details($key);
        $spki    = base64_decode((string) preg_replace('/-----[^-]+-----|\s+/', '', $details['key']), true);
        self::assertIsString($der);
        self::assertIsString($spki);
        return [$pem, $der, $spki];
    }

    public function testRejectsNonCertificateInput(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TlsaGenerator()->generate('invalid', 3, 1, 1);
    }
}

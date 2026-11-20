<?php

declare(strict_types=1);

namespace TowerDNS\Tests\Application\DNS;

use PHPUnit\Framework\TestCase;
use TowerDNS\Application\DNS\TlsaGenerator;

/** @psalm-api PHPUnit discovers this test at runtime. */
final class TlsaGeneratorTest extends TestCase
{
    public function testAllCombinationsAndDeduplication(): void
    {
        [$pem, $der, $spki]       = $this->certificate(false);
        [$caPem, $caDer, $caSpki] = $this->certificate(true);
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
        [$leaf]    = $this->certificate(false);
        [$ca]      = $this->certificate(true);
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
    private function certificate(bool $ca): array
    {
        $pem = $this->fixture($ca ? 'ca' : 'rsa');
        $der = base64_decode((string) preg_replace('/-----[^-]+-----|\s+/', '', $pem), true);
        $key = openssl_pkey_get_public($pem);
        self::assertNotFalse($key);
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        self::assertIsString($details['key']);
        $spki = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $details['key']) ?? '', true);
        self::assertIsString($der);
        self::assertIsString($spki);
        return [$pem, $der, $spki];
    }

    public function testSignedDualCertificateBundleUsesIndependentSpkiVectors(): void
    {
        $generator = new TlsaGenerator();
        $rsa       = $this->fixture('rsa');
        $ec        = $this->fixture('ecdsa');
        $ca        = $this->fixture('ca');
        $bundle    = $ca . $rsa . $ec;
        self::assertSame([
            '3 1 1 fbcf393845758eddb748223fa7ffc57dd55cf0682e04230a449850100b4d6990',
            '3 1 1 57a5102f89d9420924abe803e29c1a90107fefc0de414ceda90a1460503bc604',
        ], $generator->generate($bundle, 3, 1, 1));
        self::assertSame($generator->generate($ec, 1, 0, 2), $generator->generate($bundle, 1, 0, 2, [2]));
        self::assertSame($generator->generate($ca, 2, 1, 1), $generator->generate($bundle, 2, 1, 1));
        $this->expectException(\InvalidArgumentException::class);
        $generator->generate($bundle, 2, 1, 1, [1]);
    }

    public function testCertificateDiagnosticsKeepHostnameAndTrustSeparate(): void
    {
        $generator = new TlsaGenerator();
        $rsa       = $this->fixture('rsa');
        $ca        = $this->fixture('ca');
        $complete  = $generator->inspect($rsa . $ca, 'service.example.test');
        self::assertSame('listed', $complete[0]['hostname']);
        self::assertSame('not_established', $complete[0]['pkix_chain']);
        self::assertSame('not_checked', $complete[1]['pkix_chain']);
        $incomplete = $generator->inspect($rsa, 'another.example.test');
        self::assertSame('not_listed', $incomplete[0]['hostname']);
        self::assertSame('not_established', $incomplete[0]['pkix_chain']);
    }

    public function testEd25519AndEd448Spki(): void
    {
        $generator = new TlsaGenerator();
        self::assertSame(['3 1 1 f8800cb921428b33564a61db7437d9c1fe4020d72f2751f78a9787bfd1e94461'], $generator->generate($this->fixture('ed25519'), 3, 1, 1));
        self::assertSame(['3 1 1 e1d7996d0bec71838ebabfd4d992fc164b26c92c23f2f0287a638479d0c7aaf6'], $generator->generate($this->fixture('ed448'), 3, 1, 1));
    }

    private function fixture(string $name): string
    {
        $pem = file_get_contents(dirname(__DIR__, 2) . '/Fixtures/tlsa/' . $name . '.pem');
        self::assertIsString($pem);
        return $pem;
    }

    public function testRejectsNonCertificateInput(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TlsaGenerator()->generate('invalid', 3, 1, 1);
    }
}

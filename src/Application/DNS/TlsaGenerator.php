<?php

declare(strict_types=1);

namespace TowerDNS\Application\DNS;

/** Generates association data from explicitly supplied X.509 certificates. */
final class TlsaGenerator
{
    /** @return list<array{index: int, subject: string, issuer: string, is_ca: bool, key_type: string, fingerprint: string}> */
    public function inspect(string $input): array
    {
        $result = [];
        foreach ($this->certificates($input) as $index => $der) {
            $certificate = $this->readCertificate($der);
            $parsed      = openssl_x509_parse($certificate);
            $key         = openssl_pkey_get_public($certificate);
            $details     = $key === false ? false : openssl_pkey_get_details($key);
            if (!is_array($parsed) || !is_array($details)) {
                throw new \InvalidArgumentException('Certificate metadata cannot be read.');
            }
            $result[] = [
                'index'    => $index,
                'subject'  => self::name($parsed['subject'] ?? []),
                'issuer'   => self::name($parsed['issuer'] ?? []),
                'is_ca'    => str_contains($parsed['extensions']['basicConstraints'] ?? '', 'CA:TRUE'),
                'key_type' => match ($details['type']) {
                    OPENSSL_KEYTYPE_RSA                                                                => 'RSA', OPENSSL_KEYTYPE_EC => 'ECDSA',
                    defined('OPENSSL_KEYTYPE_ED25519') && $details['type'] === OPENSSL_KEYTYPE_ED25519 => 'Ed25519',
                    default                                                                            => 'Other',
                },
                'fingerprint' => hash('sha256', $der),
            ];
        }
        return $result;
    }

    /** @return list<string> */
    public function generate(string $input, int $usage, int $selector, int $matchingType, ?array $selectedIndexes = null): array
    {
        if ($usage < 0 || $usage > 3 || $selector < 0 || $selector > 1 || $matchingType < 0 || $matchingType > 2) {
            throw new \InvalidArgumentException('Unsupported TLSA parameters.');
        }
        if (strlen($input) > 1048576 || $input === '') {
            throw new \InvalidArgumentException('Invalid certificate input size.');
        }
        $certificates = $this->certificates($input);
        $metadata     = $this->inspect($input);
        $records      = [];
        $requiresCa   = in_array($usage, [0, 2], true);
        foreach ($certificates as $index => $der) {
            if ($selectedIndexes !== null && !in_array($index, $selectedIndexes, true)) {
                continue;
            }
            if ($metadata[$index]['is_ca'] !== $requiresCa) {
                continue;
            }
            $certificate = $this->readCertificate($der);
            if ($selector === 1) {
                $key     = openssl_pkey_get_public($certificate);
                $details = $key === false ? false : openssl_pkey_get_details($key);
                if ($details === false || !isset($details['key'])) {
                    throw new \InvalidArgumentException('Cannot extract certificate public key.');
                }
                $encoded  = preg_replace('/-----BEGIN PUBLIC KEY-----|-----END PUBLIC KEY-----|\s+/', '', $details['key']);
                $selected = base64_decode((string) $encoded, true);
                if ($selected === false) {
                    throw new \InvalidArgumentException('Invalid SubjectPublicKeyInfo encoding.');
                }
            } else {
                $selected = $der;
            }
            $data = match ($matchingType) {
                0 => bin2hex($selected), 1 => hash('sha256', $selected), 2 => hash('sha512', $selected)
            };
            $records[] = "{$usage} {$selector} {$matchingType} {$data}";
        }
        if ($records === []) {
            throw new \InvalidArgumentException($requiresCa ? 'Select at least one CA certificate for this usage.' : 'Select at least one end-entity certificate for this usage.');
        }
        return array_values(array_unique($records));
    }

    /** @return list<string> */
    private function certificates(string $input): array
    {
        if (strlen($input) > 1048576 || $input === '') {
            throw new \InvalidArgumentException('Invalid certificate input size.');
        }
        $certificates = [];
        if (str_contains($input, '-----BEGIN')) {
            preg_match_all('/-----BEGIN CERTIFICATE-----\s*([A-Za-z0-9+\/=\s]+)-----END CERTIFICATE-----/', $input, $matches);
            $remainder = preg_replace('/-----BEGIN CERTIFICATE-----\s*[A-Za-z0-9+\/=\s]+-----END CERTIFICATE-----/', '', $input);
            if (trim((string) $remainder) !== '' || count($matches[1]) === 0 || count($matches[1]) > 32) {
                throw new \InvalidArgumentException('Expected one or more PEM certificates.');
            }
            foreach ($matches[1] as $body) {
                $der = base64_decode(preg_replace('/\s+/', '', $body) ?? '', true);
                if ($der === false) {
                    throw new \InvalidArgumentException('Invalid PEM certificate.');
                }
                $certificates[] = $der;
            }
        } else {
            $certificates[] = $input;
        }
        return $certificates;
    }

    private function readCertificate(string $der): \OpenSSLCertificate
    {
        set_error_handler(static function (int $severity, string $message): never {
            throw new \InvalidArgumentException('Invalid X.509 certificate: ' . $message);
        }, E_WARNING);
        try {
            $certificate = openssl_x509_read(self::pem('CERTIFICATE', $der));
        } finally {
            restore_error_handler();
        }
        if ($certificate === false) {
            throw new \InvalidArgumentException('Invalid X.509 certificate.');
        }
        return $certificate;
    }

    /** @param array<string, mixed> $name */
    private static function name(array $name): string
    {
        return implode(', ', array_map(static fn(string $key, mixed $value): string => $key . '=' . (is_scalar($value) ? (string) $value : ''), array_keys($name), array_values($name)));
    }

    private static function pem(string $label, string $der): string
    {
        return "-----BEGIN {$label}-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END {$label}-----\n";
    }
}

<?php

declare(strict_types=1);

namespace TowerDNS\Application\DNS;

/** Generates association data from explicitly supplied X.509 certificates.
 * @phpstan-type CertificateInfo array{index: int, subject: string, issuer: string, is_ca: bool, key_type: string, fingerprint: string, validity: string, hostname: string, pkix_chain: string}
 * @psalm-type CertificateInfo = array{index: int, subject: string, issuer: string, is_ca: bool, key_type: string, fingerprint: string, validity: string, hostname: string, pkix_chain: string}
 */
final class TlsaGenerator
{
    /** @return list<CertificateInfo> */
    public function inspect(string $input, ?string $hostname = null): array
    {
        $derCertificates = $this->certificates($input);
        $result          = [];
        foreach ($derCertificates as $index => $der) {
            $certificate = $this->readCertificate($der);
            $parsed      = openssl_x509_parse($certificate);
            $key         = openssl_pkey_get_public($certificate);
            $details     = $key === false ? false : openssl_pkey_get_details($key);
            if (!is_array($parsed) || !is_array($details)) {
                throw new \InvalidArgumentException('Certificate metadata cannot be read.');
            }
            $result[] = [
                'index'       => $index,
                'subject'     => $this->name($parsed['subject'] ?? []),
                'issuer'      => $this->name($parsed['issuer'] ?? []),
                'is_ca'       => str_contains($parsed['extensions']['basicConstraints'] ?? '', 'CA:TRUE'),
                'key_type'    => $this->keyType($details['type']),
                'validity'    => $this->validity($parsed['validFrom_time_t'] ?? null, $parsed['validTo_time_t'] ?? null),
                'hostname'    => $this->hostnameStatus($hostname, $parsed['extensions']['subjectAltName'] ?? null),
                'pkix_chain'  => 'not_checked',
                'fingerprint' => hash('sha256', $der),
            ];
        }
        if ($hostname !== null) {
            return $this->checkPkixChains($derCertificates, $result);
        }
        return $result;
    }

    /** @param list<int>|null $selectedIndexes
     *  @return list<string>
     */
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
        if ($selectedIndexes !== null && array_diff($selectedIndexes, array_keys($metadata)) !== []) {
            throw new \InvalidArgumentException('The selected certificate does not exist.');
        }
        $records    = [];
        $requiresCa = in_array($usage, [0, 2], true);
        foreach ($certificates as $index => $der) {
            if ($selectedIndexes !== null && !in_array($index, $selectedIndexes, true)) {
                continue;
            }
            if ($metadata[$index]['is_ca'] !== $requiresCa) {
                if ($selectedIndexes !== null) {
                    throw new \InvalidArgumentException('Selected certificate role does not match the certificate usage.');
                }
                continue;
            }
            $certificate = $this->readCertificate($der);
            if ($selector === 1) {
                $key     = openssl_pkey_get_public($certificate);
                $details = $key === false ? false : openssl_pkey_get_details($key);
                if ($details === false || !is_string($details['key'] ?? null)) {
                    throw new \InvalidArgumentException('Cannot extract certificate public key.');
                }
                $encoded  = preg_replace('/-----BEGIN PUBLIC KEY-----|-----END PUBLIC KEY-----|\s+/', '', $details['key']);
                $selected = is_string($encoded) ? base64_decode($encoded, true) : false;
                if ($selected === false) {
                    throw new \InvalidArgumentException('Invalid SubjectPublicKeyInfo encoding.');
                }
            } else {
                $selected = $der;
            }
            $data = match ($matchingType) {
                0 => bin2hex($selected), 1 => hash('sha256', $selected), 2 => hash('sha512', $selected),
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
        set_error_handler(static function (): never {
            throw new \InvalidArgumentException('Invalid X.509 certificate.');
        }, E_WARNING);
        try {
            $certificate = openssl_x509_read($this->pem('CERTIFICATE', $der));
        } finally {
            restore_error_handler();
        }
        if ($certificate === false) {
            throw new \InvalidArgumentException('Invalid X.509 certificate.');
        }
        if (!openssl_x509_export($certificate, $canonical) || $this->pem('CERTIFICATE', $der) !== $canonical) {
            throw new \InvalidArgumentException('Expected exactly one DER-encoded certificate per input block.');
        }
        return $certificate;
    }

    /** @param array<string, mixed> $name */
    private function name(array $name): string
    {
        return implode(', ', array_map(static fn(string $key, mixed $value): string => $key . '=' . (is_scalar($value) ? (string) $value : ''), array_keys($name), array_values($name)));
    }

    /**
     * OpenSSL exposes SAN as a presentation string in PHP. Only interpret a
     * DNS-only list as a diagnostic. This is not an authenticated peer check.
     */
    private function hostnameStatus(?string $hostname, mixed $san): string
    {
        if ($hostname === null || !is_string($san)
                               || preg_match('/^DNS:(?:\*\.)?[a-z0-9.-]+(?:, DNS:(?:\*\.)?[a-z0-9.-]+)*$/iD', $san) !== 1) {
            return 'not_checked';
        }
        $host = \TowerDNS\Application\Validation\DNSNameValidator::normalise($hostname);
        foreach (explode(', ', strtolower($san)) as $entry) {
            $name = substr($entry, 4);
            if ($name === $host) {
                return 'listed';
            }
            $dot = strpos($host, '.');
            if ($dot !== false && str_starts_with($name, '*.') && substr($host, $dot) === substr($name, 1)) {
                return 'listed';
            }
        }
        return 'not_listed';
    }

    /**
     * @param list<string> $derCertificates
     * @param list<CertificateInfo> $metadata
     * @return list<CertificateInfo>
     */
    private function checkPkixChains(array $derCertificates, array $metadata): array
    {
        $caBundle = '';
        foreach ($metadata as $index => $info) {
            if ($info['is_ca']) {
                $caBundle .= $this->pem('CERTIFICATE', $derCertificates[$index]);
            }
        }

        $file = null;
        try {
            if ($caBundle !== '') {
                $temporary = tmpfile();
                if ($temporary === false) {
                    return $metadata;
                }
                $file = $temporary;
                if (fwrite($file, $caBundle) !== strlen($caBundle) || !fflush($file)) {
                    return $metadata;
                }
            }
            $untrustedPath = $file === null ? null : (stream_get_meta_data($file)['uri'] ?? null);
            if ($file !== null && $untrustedPath === null) {
                return $metadata;
            }
            $checked = [];
            foreach ($metadata as $index => $info) {
                if ($info['is_ca']) {
                    $checked[] = $info;
                    continue;
                }
                $status = openssl_x509_checkpurpose(
                    $this->readCertificate($derCertificates[$index]),
                    X509_PURPOSE_SSL_SERVER,
                    [],
                    $untrustedPath,
                );
                $info['pkix_chain'] = match ($status) {
                    true    => 'system_trusted',
                    false   => 'not_established',
                    default => 'not_checked',
                };
                $checked[] = $info;
            }
        } finally {
            if ($file !== null) {
                fclose($file);
            }
        }
        return $checked;
    }

    private function keyType(int $type): string
    {
        if ($type === OPENSSL_KEYTYPE_RSA) {
            return 'RSA';
        }
        if ($type === OPENSSL_KEYTYPE_EC) {
            return 'ECDSA';
        }
        foreach (['ED25519' => 'Ed25519', 'ED448' => 'Ed448'] as $constant => $label) {
            $name = 'OPENSSL_KEYTYPE_' . $constant;
            if (defined($name) && constant($name) === $type) {
                return $label;
            }
        }
        return 'Other';
    }

    private function validity(mixed $from, mixed $to): string
    {
        if (!is_int($from) || !is_int($to)) {
            return 'unknown';
        }
        $now = time();
        return $now < $from ? 'not_yet_valid' : ($now > $to ? 'expired' : 'within_dates');
    }

    private function pem(string $label, string $der): string
    {
        return "-----BEGIN {$label}-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END {$label}-----\n";
    }
}

<?php

declare(strict_types=1);

namespace TowerDNS\Application\DNS;

/** Generates TLSA association data and reports certificate diagnostics separately.
 * @phpstan-type CertificateInfo array{index: int, subject: string, issuer: string, is_ca: bool, key_type: string, fingerprint: string, validity: string, basic_constraints: string, key_usage: list<string>, extended_key_usage: list<string>, hostname: string, pkix_chain: string}
 * @psalm-type CertificateInfo = array{index: int, subject: string, issuer: string, is_ca: bool, key_type: string, fingerprint: string, validity: string, basic_constraints: string, key_usage: list<string>, extended_key_usage: list<string>, hostname: string, pkix_chain: string}
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
                'index'              => $index,
                'subject'            => $this->name($parsed['subject'] ?? []),
                'issuer'             => $this->name($parsed['issuer'] ?? []),
                'is_ca'              => str_contains($parsed['extensions']['basicConstraints'] ?? '', 'CA:TRUE'),
                'key_type'           => $this->keyType($details['type']),
                'validity'           => $this->validity($parsed['validFrom_time_t'] ?? null, $parsed['validTo_time_t'] ?? null),
                'basic_constraints'  => $parsed['extensions']['basicConstraints'] ?? '',
                'key_usage'          => $this->extensionList($parsed['extensions']['keyUsage'] ?? null),
                'extended_key_usage' => $this->extensionList($parsed['extensions']['extendedKeyUsage'] ?? null),
                'hostname'           => $this->hostnameStatus($hostname, $parsed['extensions']['subjectAltName'] ?? null),
                'pkix_chain'         => 'not_checked',
                'fingerprint'        => hash('sha256', $der),
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

    /**
     * Compares published TLSA RDATA with explicitly supplied certificates.
     * This only proves an association match against the input bundle; it does
     * not prove that a server presented those certificates or authenticated a
     * live TLS connection.
     *
     * @param list<string> $records
     * @return list<array{record: string, association: string, certificate_indexes: list<int>, pkix: string, dane_authentication: string}>
     */
    public function matchAssociations(string $input, array $records, ?string $hostname = null): array
    {
        $metadata         = $this->inspect($input, $hostname);
        $derCertificates  = $this->certificates($input);
        $associationCache = [];
        $pkixAnchorCache  = [];
        $results          = [];
        foreach ($records as $record) {
            if (preg_match('/^([0-3]) ([0-1]) ([0-2]) ([0-9a-fA-F]+)$/D', trim($record), $parts) !== 1) {
                throw new \InvalidArgumentException('Invalid TLSA RDATA.');
            }
            $canonical   = strtolower($parts[1] . ' ' . $parts[2] . ' ' . $parts[3] . ' ' . $parts[4]);
            $usage       = (int) $parts[1];
            $selector    = (int) $parts[2];
            $matching    = (int) $parts[3];
            $matches     = [];
            $pkixResults = [];
            foreach ($metadata as $certificate) {
                if ($certificate['is_ca'] !== in_array($usage, [0, 2], true)) {
                    continue;
                }
                $cacheKey = $certificate['index'] . ':' . $selector . ':' . $matching;
                if (!array_key_exists($cacheKey, $associationCache)) {
                    $associationCache[$cacheKey] = $this->associationForDer($derCertificates[$certificate['index']], $usage, $selector, $matching);
                }
                if ($canonical === $associationCache[$cacheKey]) {
                    $matches[] = $certificate['index'];
                    if ($usage === 1) {
                        $pkixResults[] = $certificate['pkix_chain'] === 'system_trusted'
                            && $certificate['validity']             === 'within_dates'
                            && $certificate['hostname']             === 'listed'
                                ? 'pkix_requirements_met_for_supplied_certificate'
                                : ($certificate['pkix_chain'] === 'not_checked' ? 'not_checked' : 'not_established');
                    }
                }
            }
            if ($usage === 0 && $matches !== []) {
                foreach ($matches as $anchorIndex) {
                    if (!array_key_exists($anchorIndex, $pkixAnchorCache)) {
                        $pkixAnchorCache[$anchorIndex] = $this->checkChainToTlsaTrustAnchor($input, [$anchorIndex], $metadata, $hostname);
                    }
                    $pkixResults[] = $pkixAnchorCache[$anchorIndex];
                }
            }
            $results[] = [
                'record'              => $canonical,
                'association'         => $matches === [] ? 'not_matched' : 'matched_supplied_certificate',
                'certificate_indexes' => $matches,
                'pkix'                => in_array($usage, [0, 1], true) ? ($pkixResults === [] ? 'not_established' : (in_array('pkix_requirements_met_for_supplied_certificate', $pkixResults, true) ? 'pkix_requirements_met_for_supplied_certificate' : (in_array('pkix_requirements_met_for_supplied_certificate_chain', $pkixResults, true) ? 'pkix_requirements_met_for_supplied_certificate_chain' : $pkixResults[0]))) : 'not_required_by_dane_usage',
                'dane_authentication' => 'not_tested',
            ];
        }

        return $results;
    }

    private function associationForDer(string $der, int $usage, int $selector, int $matchingType): string
    {
        $selected = $der;
        if ($selector === 1) {
            $key     = openssl_pkey_get_public($this->readCertificate($der));
            $details = $key === false ? false : openssl_pkey_get_details($key);
            if (!is_array($details) || !is_string($details['key'] ?? null)) {
                throw new \InvalidArgumentException('Cannot extract certificate public key.');
            }
            $encoded = preg_replace('/-----BEGIN PUBLIC KEY-----|-----END PUBLIC KEY-----|\s+/', '', $details['key']);
            $spki    = is_string($encoded) ? base64_decode($encoded, true) : false;
            if ($spki === false) {
                throw new \InvalidArgumentException('Invalid SubjectPublicKeyInfo encoding.');
            }
            $selected = $spki;
        }
        $data = match ($matchingType) {
            0       => bin2hex($selected), 1 => hash('sha256', $selected), 2 => hash('sha512', $selected),
            default => throw new \InvalidArgumentException('Unsupported TLSA matching type.'),
        };
        return "{$usage} {$selector} {$matchingType} {$data}";
    }

    /**
     * @param list<int> $anchorIndexes
     * @param list<CertificateInfo> $metadata
     */
    private function checkChainToTlsaTrustAnchor(string $input, array $anchorIndexes, array $metadata, ?string $hostname): string
    {
        if ($hostname === null || $hostname === '') {
            return 'not_checked';
        }
        $derCertificates = $this->certificates($input);
        foreach ($anchorIndexes as $anchorIndex) {
            $anchorFile = tmpfile();
            $chainFile  = tmpfile();
            if ($anchorFile === false || $chainFile === false) {
                if (is_resource($anchorFile)) {
                    fclose($anchorFile);
                }
                if (is_resource($chainFile)) {
                    fclose($chainFile);
                }
                return 'not_checked';
            }
            try {
                $anchorPem = $this->pem('CERTIFICATE', $derCertificates[$anchorIndex]);
                if (fwrite($anchorFile, $anchorPem) !== strlen($anchorPem) || !fflush($anchorFile)) {
                    return 'not_checked';
                }
                $chain = '';
                foreach ($metadata as $candidate) {
                    if ($candidate['index'] !== $anchorIndex && $candidate['is_ca']) {
                        $chain .= $this->pem('CERTIFICATE', $derCertificates[$candidate['index']]);
                    }
                }
                if ($chain !== '' && (fwrite($chainFile, $chain) !== strlen($chain) || !fflush($chainFile))) {
                    return 'not_checked';
                }
                $caPath    = stream_get_meta_data($anchorFile)['uri'] ?? null;
                $chainPath = $chain === '' ? null : (stream_get_meta_data($chainFile)['uri'] ?? null);
                if (!is_string($caPath) || ($chain !== '' && !is_string($chainPath))) {
                    return 'not_checked';
                }
                foreach ($metadata as $leaf) {
                    if ($leaf['is_ca'] || $leaf['validity'] !== 'within_dates' || $leaf['hostname'] !== 'listed') {
                        continue;
                    }
                    $certificate = $this->readCertificate($derCertificates[$leaf['index']]);
                    $status      = openssl_x509_checkpurpose(
                        $certificate,
                        X509_PURPOSE_SSL_SERVER,
                        ['cafile' => $caPath],
                        $chainPath,
                    );
                    if ($status === true || $status === 1) {
                        return 'pkix_requirements_met_for_supplied_certificate_chain';
                    }
                }
            } finally {
                fclose($anchorFile);
                fclose($chainFile);
            }
        }

        return 'not_established';
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
        if ($hostname === null || !is_string($san) || preg_match('/(?:^|, )DNS:(?:\*\.)?[a-z0-9.-]+/i', $san) !== 1) {
            return 'not_checked';
        }
        try {
            $host = \TowerDNS\Application\Validation\DNSNameValidator::normalise($hostname);
        } catch (\InvalidArgumentException) {
            return 'not_listed';
        }
        foreach (explode(',', $san) as $entry) {
            $entry = trim($entry);
            if (!str_starts_with(strtolower($entry), 'dns:')) {
                continue;
            }
            $candidate = strtolower(substr($entry, 4));
            if ($candidate === $host) {
                return 'listed';
            }
            if (str_starts_with($candidate, '*.')) {
                try {
                    $suffix = \TowerDNS\Application\Validation\DNSNameValidator::normalise(substr($candidate, 2));
                } catch (\InvalidArgumentException) {
                    continue;
                }
                $ending = '.' . $suffix;
                if (str_ends_with($host, $ending)) {
                    $leftmost = substr($host, 0, -strlen($ending));
                    if ($leftmost !== '' && !str_contains($leftmost, '.')) {
                        return 'listed';
                    }
                }
            }
        }

        return 'not_listed';
    }

    /** @return list<string> */
    private function extensionList(mixed $extension): array
    {
        if (!is_string($extension) || trim($extension) === '') {
            return [];
        }

        return array_values(array_filter(array_map(trim(...), explode(',', $extension)), static fn(string $value): bool => $value !== ''));
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

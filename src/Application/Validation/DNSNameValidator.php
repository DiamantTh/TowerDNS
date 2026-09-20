<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Validation;

/**
 * Normalises and validates DNS labels and FQDNs.
 *
 * IDN names are converted to A-label (Punycode, RFC 3492) form so the
 * canonical TowerDNS representation is always ASCII.
 */
final class DNSNameValidator
{
    /**
     * @return string normalised lowercase A-label representation, without trailing dot
     */
    public static function normalise(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('DNS-Name darf nicht leer sein.');
        }
        $name = rtrim($name, '.');

        if (function_exists('idn_to_ascii')) {
            $converted = idn_to_ascii($name, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($converted !== false) {
                $name = $converted;
            }
        }

        $name = strtolower($name);

        if (strlen($name) > 253) {
            throw new \InvalidArgumentException('DNS-Name ueberschreitet 253 Zeichen.');
        }

        if (!preg_match('/^(?:[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?\.)+[a-z]{2,63}$/i', $name)) {
            throw new \InvalidArgumentException(sprintf('Ungueltiger DNS-Name: %s', $name));
        }

        return $name;
    }

    public static function isValid(string $name): bool
    {
        try {
            self::normalise($name);
            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Validate and normalize an RR owner relative to a managed zone.
     *
     * TowerDNS passes relative owner names to provider adapters. Accepting an
     * absolute name is useful when editing existing records, but it must be
     * inside the selected zone; otherwise adapters can disagree about whether
     * an out-of-zone FQDN is relative or absolute.
     */
    public static function normaliseRecordOwner(string $owner, string $zoneName): string
    {
        $owner = trim($owner);
        $zone  = self::normalise($zoneName);

        if ($owner === '' || $owner === '@') {
            return '';
        }

        $absolute = str_ends_with($owner, '.');
        if ($absolute && str_ends_with($owner, '..')) {
            throw new \InvalidArgumentException('Ungültiger DNS-Record-Name.');
        }
        $owner = rtrim($owner, '.');

        if (function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($owner, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($ascii === false) {
                throw new \InvalidArgumentException('Ungültiger DNS-Record-Name.');
            }
            $owner = $ascii;
        }

        $owner = strtolower($owner);
        if ($owner === $zone) {
            return '';
        }

        $suffix = '.' . $zone;
        if (str_ends_with($owner, $suffix)) {
            $owner = substr($owner, 0, -strlen($suffix));
        } elseif ($absolute) {
            throw new \InvalidArgumentException('Der absolute DNS-Record-Name liegt außerhalb der verwalteten Zone.');
        }

        $labels = explode('.', $owner);
        foreach ($labels as $index => $label) {
            if ($label === '*' && $index !== 0) {
                throw new \InvalidArgumentException('Ein Wildcard-Label ist nur am Anfang eines DNS-Record-Namens zulässig.');
            }
            if (strlen($label) > 63 || preg_match('/^(?:\\*|[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?)$/D', $label) !== 1) {
                throw new \InvalidArgumentException('Ungültiger DNS-Record-Name.');
            }
        }

        if (strlen($owner . '.' . $zone) > 253) {
            throw new \InvalidArgumentException('DNS-Record-Name überschreitet die maximale Länge.');
        }

        return $owner;
    }
}

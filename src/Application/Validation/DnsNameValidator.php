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
final class DnsNameValidator
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
}

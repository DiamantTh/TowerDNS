<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Dns;

use TowerDNS\Domain\DNS\Rrset;

/** DNS-semantic RRset equality for provider read-back verification. */
final class RrsetComparator
{
    public static function equals(Rrset $expected, Rrset $observed): bool
    {
        if (strtolower(rtrim($expected->ownerName, '.')) !== strtolower(rtrim($observed->ownerName, '.'))
            || !$expected->type->equals($observed->type)
            || $expected->ttl !== $observed->ttl) {
            return false;
        }

        return self::rdataSet($expected) === self::rdataSet($observed);
    }

    /** @return list<string> */
    private static function rdataSet(Rrset $rrset): array
    {
        $items = array_map(
            static fn(string $rdata): string => RdataCanonicalizer::canonicalize($rrset->type, $rdata),
            $rrset->rdata,
        );
        $items = array_values(array_unique($items));
        sort($items, SORT_STRING);
        return $items;
    }
}

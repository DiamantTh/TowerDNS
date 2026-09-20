<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Validation;

use TowerDNS\Application\DNS\RdataCanonicalizer;
use TowerDNS\Domain\DNS\DNSRecordType;
use TowerDNS\Domain\DNS\RecordType;

/**
 * Lightweight TTL and rdata sanity checks shared by all provider adapters.
 *
 * Adapters may add provider-specific rules on top, but the Application layer
 * normalises everything before handing data over.
 */
final class RecordValidator
{
    public const int MIN_TTL = 30;
    public const int MAX_TTL = 604800;

    public static function assertTtl(int $ttl): void
    {
        if ($ttl < self::MIN_TTL || $ttl > self::MAX_TTL) {
            throw new \InvalidArgumentException(sprintf(
                'TTL %d liegt ausserhalb des erlaubten Bereichs (%d-%d).',
                $ttl,
                self::MIN_TTL,
                self::MAX_TTL,
            ));
        }
    }

    public static function assertContent(RecordType $type, string $content): void
    {
        self::normaliseContent($type, $content);
    }

    public static function normaliseContent(RecordType $type, string $content): string
    {
        $content = trim($content);
        if ($content === '') {
            throw new \InvalidArgumentException('Record-Content darf nicht leer sein.');
        }

        return RdataCanonicalizer::canonicalize(DNSRecordType::parse($type->value), $content);
    }
}

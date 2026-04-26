<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Application\Validation;

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
        $content = trim($content);
        if ($content === '') {
            throw new \InvalidArgumentException('Record-Content darf nicht leer sein.');
        }

        switch ($type) {
            case RecordType::A:
                if (!filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    throw new \InvalidArgumentException('A-Record erwartet eine IPv4-Adresse.');
                }
                break;
            case RecordType::AAAA:
                if (!filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    throw new \InvalidArgumentException('AAAA-Record erwartet eine IPv6-Adresse.');
                }
                break;
            default:
                // Other record types are validated by the provider adapter.
                break;
        }
    }
}

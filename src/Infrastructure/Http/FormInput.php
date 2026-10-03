<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Http;

/** Normalizes an untrusted parsed form body before handlers consume fields. */
final class FormInput
{
    /**
     * Keep only scalar form values, so nested attacker-controlled arrays never
     * reach string casts in CSRF checks, identifiers, or credential fields.
     *
     * @return array<string, string>
     */
    public static function fromParsedBody(mixed $parsedBody): array
    {
        if ($parsedBody instanceof \stdClass) {
            $parsedBody = get_object_vars($parsedBody);
        }

        if (!is_array($parsedBody)) {
            return [];
        }

        $fields = [];
        foreach ($parsedBody as $name => $value) {
            if (!is_string($name) || !is_scalar($value)) {
                continue;
            }

            $fields[$name] = (string) $value;
        }

        return $fields;
    }
}

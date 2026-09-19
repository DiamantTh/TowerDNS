<?php

declare(strict_types=1);

namespace TowerDNS\Application\Services;

/** Canonical, single-source definitions for personal presentation preferences. */
final class UserPreferences
{
    public const string DEFAULT_LANGUAGE = 'en-GB';
    public const string DEFAULT_LOCALE   = 'en-GB';
    public const string DEFAULT_TIMEZONE = 'UTC';

    /** @return list<string> */
    public static function languages(): array
    {
        return ['en-GB', 'de-DE'];
    }
    /** @return list<string> */
    public static function locales(): array
    {
        return ['en-GB', 'de-DE'];
    }
    /** @return list<string> */
    public static function timezones(): array
    {
        return \DateTimeZone::listIdentifiers();
    }

    public static function normalizeLanguage(string $value): ?string
    {
        return self::normalizeCatalogValue($value, self::languages());
    }
    public static function normalizeLocale(string $value): ?string
    {
        return self::normalizeCatalogValue($value, self::locales());
    }
    public static function normalizeTimezone(string $value): ?string
    {
        $value = trim($value);
        return in_array($value, self::timezones(), true) ? $value : null;
    }

    /** @param list<string> $allowed */
    private static function normalizeCatalogValue(string $value, array $allowed): ?string
    {
        $value = str_replace('_', '-', trim($value));
        foreach ($allowed as $candidate) {
            if (strtolower($candidate) === strtolower($value) || strtolower(explode('-', $candidate)[0]) === strtolower($value)) {
                return $candidate;
            }
        }
        return null;
    }
}

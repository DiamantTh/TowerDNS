<?php

declare(strict_types=1);

namespace TowerDNS\Application\Services;

/** UI catalogues currently shipped by the application. */
final class SupportedLocales
{
    public const string DEFAULT = UserPreferences::DEFAULT_LANGUAGE;

    /** @return list<string> */
    public static function all(): array
    {
        return UserPreferences::languages();
    }

    public static function normalize(string $locale): ?string
    {
        return UserPreferences::normalizeLanguage($locale);
    }
}

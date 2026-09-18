<?php

declare(strict_types=1);

namespace TowerDNS\Application\Services;

/** UI catalogues currently shipped by the application. */
final class SupportedLocales
{
    public const string DEFAULT = 'en-GB';

    /** @return list<string> */
    public static function all(): array
    {
        return ['en-GB', 'de-DE'];
    }

    public static function normalize(string $locale): ?string
    {
        $locale = str_replace('_', '-', trim($locale));
        return match (strtolower($locale)) {
            'en', 'en-gb' => 'en-GB',
            'de', 'de-de' => 'de-DE',
            default       => null,
        };
    }
}

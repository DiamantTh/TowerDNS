<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 TowerDNS contributors

declare(strict_types=1);

namespace TowerDNS\Infrastructure\Twig;

use Laminas\I18n\Translator\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Exposes the Laminas Translator to Twig templates.
 *
 * Usage in templates:
 *   {{ 'Hello'|trans }}
 *   {{ trans('Hello') }}
 */
final class TranslatorExtension extends AbstractExtension
{
    public function __construct(private readonly TranslatorInterface $translator) {}

    public function getFilters(): array
    {
        return [
            new TwigFilter(
                'trans',
                fn(string $message, string $domain = 'default', ?string $locale = null): string => $this->translator->translate($message, $domain, $locale),
            ),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction(
                'trans',
                fn(string $message, string $domain = 'default', ?string $locale = null): string => $this->translator->translate($message, $domain, $locale),
            ),
        ];
    }
}

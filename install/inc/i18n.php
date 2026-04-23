<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * TowerDNS Installer — Internationalisierung
 *
 * Verwendet Laminas\I18n\Translator mit PhpArray-Dateien aus install/lang/.
 * Unterstützte Locales: de-DE, en-GB, cs-CZ, es-ES, fr-FR, hu-HU,
 *                       it-IT, nl-NL, pl-PL, pt-PT, ro-RO, sv-SE
 */

define('INSTALLER_LANGS', [
    'de-DE' => 'Deutsch',
    'en-GB' => 'English',
    'cs-CZ' => 'Čeština',
    'es-ES' => 'Español',
    'fr-FR' => 'Français',
    'hu-HU' => 'Magyar',
    'it-IT' => 'Italiano',
    'nl-NL' => 'Nederlands',
    'pl-PL' => 'Polski',
    'pt-PT' => 'Português',
    'ro-RO' => 'Română',
    'sv-SE' => 'Svenska',
]);

/**
 * Ermittelt die aktive Installer-Locale (Session → Accept-Language → Fallback).
 */
function detectInstallerLocale(): string
{
    if (isset($_GET['lang']) && array_key_exists($_GET['lang'], INSTALLER_LANGS)) {
        $_SESSION['installer_lang'] = $_GET['lang'];
    }

    if (!empty($_SESSION['installer_lang']) && array_key_exists($_SESSION['installer_lang'], INSTALLER_LANGS)) {
        return $_SESSION['installer_lang'];
    }

    // Accept-Language auswerten
    $accept = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
    foreach (explode(',', $accept) as $tag) {
        $lang = trim(strtok($tag, ';'));
        // Exakter Treffer (z. B. de-DE)
        $normalized = preg_replace('/[^a-zA-Z\-]/', '', $lang) ?? $lang;
        if (array_key_exists($normalized, INSTALLER_LANGS)) {
            return $normalized;
        }
        // Prefix-Treffer (z. B. "de" → "de-DE")
        $prefix = substr($normalized, 0, 2);
        foreach (array_keys(INSTALLER_LANGS) as $supported) {
            if (str_starts_with(strtolower($supported), strtolower($prefix))) {
                return $supported;
            }
        }
    }

    return 'en-GB';
}

/**
 * Initialisiert den Laminas-Translator und gibt die aktive Locale zurück.
 * Fällt bei fehlender Sprachdatei auf en-GB zurück.
 */
function initTranslator(): string
{
    $locale  = detectInstallerLocale();
    $langDir = INSTALL_DIR . '/lang';

    $translator = new \Laminas\I18n\Translator\Translator();
    $translator->setLocale($locale);

    // en-GB immer als Basis laden (Fallback für fehlende Schlüssel)
    $enFile = $langDir . '/en-GB.php';
    if (is_file($enFile)) {
        $translator->addTranslationFile('phparray', $enFile, 'installer', 'en-GB');
    }

    // Ziel-Locale laden (falls Datei existiert und != en-GB)
    if ($locale !== 'en-GB') {
        $localeFile = $langDir . '/' . $locale . '.php';
        if (is_file($localeFile)) {
            $translator->addTranslationFile('phparray', $localeFile, 'installer', $locale);
        } else {
            // Fallback auf en-GB
            $locale = 'en-GB';
            $translator->setLocale('en-GB');
        }
    }

    // Globale Übersetzungsfunktion registrieren
    $GLOBALS['_installer_translator'] = $translator;
    $GLOBALS['_installer_locale']     = str_replace('-', '_', $locale);

    return $locale;
}

/**
 * Übersetzt einen Schlüssel aus der text-domain "installer".
 */
function t(string $key, string $domain = 'installer'): string
{
    /** @var \Laminas\I18n\Translator\Translator|null $t */
    $t = $GLOBALS['_installer_translator'] ?? null;
    if ($t === null) {
        return $key;
    }

    return $t->translate($key, $domain);
}

<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * TowerDNS Installer — Deutsche Übersetzungen (de-DE)
 * Text domain: installer
 */
return [

    '' => [
        'plural_forms' => 'nplurals=2; plural=(n!=1);',
    ],

    // ── Layout / Navigation ───────────────────────────────────────────────
    'layout.title'       => 'Installer',
    'layout.lang_switch' => 'Sprache wechseln',
    'layout.to_app'      => 'Zur Anwendung',

    'nav.back'    => 'Zurück',
    'nav.next'    => 'Weiter',
    'nav.restart' => 'Neu starten',

    // ── Schritt-Labels ────────────────────────────────────────────────────
    'steps.s1' => 'System-Check',
    'steps.s2' => 'Konfiguration',
    'steps.s3' => 'Bestätigung',

    // ── Zugang verweigert ─────────────────────────────────────────────────
    'access.title'             => 'Installer gesperrt',
    'access.protected'         => 'Installer durch Token geschützt',
    'access.protected_hint'    => 'Gib den Installer-Token ein, der beim ersten Aufruf automatisch erstellt wurde.',
    'access.token_label'       => 'Installer-Token',
    'access.token_placeholder' => 'Token hier einfügen …',
    'access.unlock'            => 'Entsperren',
    'access.invalid_token'     => 'Ungültiger Token. Bitte Eingabe prüfen.',
    'access.token_retrieve'    => 'Token auf dem Server abrufen:',

    // ── Installer gesperrt ────────────────────────────────────────────────
    'locked.title'          => 'Installer gesperrt',
    'locked.heading'        => 'Installer gesperrt',
    'locked.body'           => 'TowerDNS ist bereits installiert. Der Installer ist daher deaktiviert.',
    'locked.recommendation' => 'Wir empfehlen dringend, den gesamten install/-Ordner zu löschen:',

    // ── Schritt 1: System-Check ───────────────────────────────────────────
    'step1.heading'            => 'System-Check',
    'step1.subheading'         => 'Folgende Voraussetzungen müssen vor der Installation erfüllt sein.',
    'step1.col_check'          => 'Prüfung',
    'step1.col_status'         => 'Status',
    'step1.col_detail'         => 'Details',
    'step1.required'           => 'Pflicht',
    'step1.ok'                 => 'OK',
    'step1.missing'            => 'Fehlt',
    'step1.notice'             => 'Hinweis',
    'step1.vendor_heading'     => 'Composer vendor/-Verzeichnis fehlt',
    'step1.vendor_body'        => 'Bitte folgenden Befehl im Projektstamm ausführen:',
    'step1.vendor_reload'      => 'Danach diese Seite neu laden.',
    'step1.vendor_missing'     => 'Composer vendor/-Verzeichnis fehlt. Bitte ausführen: composer install --no-dev',
    'step1.reqs_not_met'       => 'Nicht alle Pflichtprüfungen bestanden. Bitte Probleme beheben, bevor du fortfährst.',
    'step1.btn_next'           => 'Weiter zur Konfiguration',
    'step1.btn_disabled_title' => 'Alle Pflichtprüfungen müssen zuerst bestanden werden',

    // ── Schritt 2: Konfiguration ──────────────────────────────────────────
    'step2.heading'    => 'Konfiguration',
    'step2.subheading' => 'Konfiguriere Datenbank, Admin-Konto, Anwendungseinstellungen und DNS-Provider.',

    'step2.db_section'     => 'Datenbank',
    'step2.db_driver'      => 'Datenbank-Treiber',
    'step2.hostname'       => 'Host',
    'step2.port'           => 'Port',
    'step2.dbname'         => 'Datenbankname',
    'step2.dbuser'         => 'Datenbankbenutzer',
    'step2.dbpass'         => 'Datenbankpasswort',
    'step2.db_create'      => 'Datenbank und Benutzer automatisch anlegen (erfordert Root-/Superuser-Zugriff)',
    'step2.root_hint'      => 'Die Root-Zugangsdaten werden nur temporär verwendet, um Datenbank und Benutzer anzulegen. Sie werden nie gespeichert.',
    'step2.root_user'      => 'Root-Benutzer',
    'step2.root_pass'      => 'Root-Passwort',
    'step2.sqlite_path'    => 'SQLite-Dateipfad',
    'step2.sqlite_hint'    => 'Leer lassen für den Standard-Pfad in data/.',
    'step2.pgsql_hint'     => 'Datenbank und Benutzer müssen für PostgreSQL bereits existieren.',

    'step2.admin_section'      => 'Admin-Konto',
    'step2.admin_username'     => 'Benutzername',
    'step2.admin_email'        => 'E-Mail-Adresse',
    'step2.admin_pass'         => 'Passwort',
    'step2.admin_pass_min'     => 'mind. 12 Zeichen',
    'step2.admin_pass_confirm' => 'Passwort wiederholen',
    'step2.pass_mismatch_live' => 'Passwörter stimmen nicht überein.',

    'step2.app_section'      => 'Anwendungseinstellungen',
    'step2.app_name'         => 'Anwendungsname',
    'step2.app_domain'       => 'Domain',
    'step2.app_domain_hint'  => 'optional',
    'step2.app_domain_help'  => 'z. B. tower.example.com — wird für Cookie-Domain und HSTS verwendet.',
    'step2.app_theme'        => 'Theme',
    'step2.app_https'        => 'HTTPS erzwingen / Strict-Transport-Security',
    'step2.btn_next'         => 'Weiter zur Bestätigung',

    // Provider-Abschnitt
    'step2.provider_section'                 => 'DNS-Provider',
    'step2.provider_hint'                    => 'Wähle mindestens einen DNS-Provider und trage die entsprechenden Zugangsdaten ein.',
    'step2.provider_desec_token'             => 'deSEC API-Token',
    'step2.provider_powerdns_url'            => 'PowerDNS API-Basis-URL',
    'step2.provider_powerdns_key'            => 'PowerDNS API-Key',
    'step2.provider_powerdns_server'         => 'Server-ID',
    'step2.provider_powerdns_server_default' => 'Standard: localhost',
    'step2.provider_cloudflare_token'        => 'Cloudflare API-Token',
    'step2.provider_inwx_user'               => 'INWX-Benutzername',
    'step2.provider_inwx_pass'               => 'INWX-Passwort',

    // Validierungsfehler
    'step2.invalid_driver'                    => 'Ungültiger Datenbank-Treiber.',
    'step2.invalid_sqlite_path'               => 'Ungültiger SQLite-Pfad.',
    'step2.dir_create_failed'                 => 'Verzeichnis konnte nicht erstellt werden: %s',
    'step2.sqlite_error'                      => 'SQLite-Verbindung fehlgeschlagen: %s',
    'step2.host_invalid'                      => 'Ungültiger Hostname.',
    'step2.port_invalid'                      => 'Ungültige Portnummer (1–65535).',
    'step2.dbname_invalid_63'                 => 'Datenbankname muss 1–63 alphanumerische Zeichen enthalten (a-z, 0-9, _).',
    'step2.dbname_invalid_64'                 => 'Datenbankname muss 1–64 alphanumerische Zeichen enthalten (a-z, 0-9, _).',
    'step2.dbuser_invalid_63'                 => 'Datenbankbenutzer muss 1–63 alphanumerische Zeichen enthalten (a-z, 0-9, _).',
    'step2.dbuser_invalid_32'                 => 'Datenbankbenutzer muss 1–32 alphanumerische Zeichen enthalten (a-z, 0-9, _).',
    'step2.pgsql_error'                       => 'PostgreSQL-Verbindung fehlgeschlagen: %s',
    'step2.root_user_invalid'                 => 'Ungültiger Root-Benutzername.',
    'step2.root_error'                        => 'Fehler beim Anlegen von Datenbank/Benutzer: %s',
    'step2.mysql_error'                       => 'MySQL/MariaDB-Verbindung fehlgeschlagen: %s',
    'step2.admin_user_invalid'                => 'Benutzername muss 3–50 Zeichen enthalten (a-z, 0-9, -, _, .).',
    'step2.admin_email_invalid'               => 'Ungültige oder zu lange E-Mail-Adresse.',
    'step2.admin_pass_short'                  => 'Passwort muss mindestens 12 Zeichen lang sein.',
    'step2.admin_pass_mismatch'               => 'Passwörter stimmen nicht überein.',
    'step2.app_name_invalid'                  => 'Anwendungsname muss 1–100 Zeichen lang sein.',
    'step2.app_domain_invalid'                => 'Ungültiger Domainname (nur a-z, 0-9, -, .).',
    'step2.provider_none_selected'            => 'Bitte mindestens einen DNS-Provider konfigurieren.',
    'step2.provider_desec_token_invalid'      => 'deSEC: API-Token darf nicht leer sein.',
    'step2.provider_powerdns_url_invalid'     => 'PowerDNS: ungültige oder fehlende Basis-URL.',
    'step2.provider_powerdns_key_invalid'     => 'PowerDNS: API-Key darf nicht leer sein.',
    'step2.provider_cloudflare_token_invalid' => 'Cloudflare: API-Token darf nicht leer sein.',
    'step2.provider_inwx_user_invalid'        => 'INWX: Benutzername darf nicht leer sein.',
    'step2.provider_inwx_pass_invalid'        => 'INWX: Passwort darf nicht leer sein.',

    // ── Schritt 3: Bestätigung ────────────────────────────────────────────
    'step3.heading'             => 'Installation bestätigen',
    'step3.subheading'          => 'Bitte Konfiguration prüfen, bevor die Installation gestartet wird.',
    'step3.warning'             => 'Vorhandene Konfigurationsdateien werden gesichert. Tabellen werden in der Datenbank angelegt.',
    'step3.confirm'             => 'Installation jetzt starten?',
    'step3.btn_install'         => 'Jetzt installieren',
    'step3.session_lost'        => 'Session-Daten verloren. Bitte den Installer neu starten.',
    'step3.install_failed'      => 'Installation fehlgeschlagen: %s',
    'step3.provider_configured' => 'Konfiguriert ✓',

    // ── Erfolg ────────────────────────────────────────────────────────────
    'success.heading'          => 'Installation erfolgreich!',
    'success.admin_user'       => 'Admin-Benutzername',
    'success.admin_pass'       => 'Admin-Passwort',
    'success.pass_warning'     => 'Passwort jetzt notieren — es wird nicht erneut angezeigt!',
    'success.security_hint'    => 'Wichtig',
    'success.security_body'    => 'Lösche den install/-Ordner oder nutze den Button unten, um den Installer zu entfernen. Ihn online zu lassen ist ein Sicherheitsrisiko.',
    'success.confirm_delete'   => 'Gesamten install/-Ordner löschen?',
    'success.delete_installer' => 'Installer jetzt löschen',

    // ── Anforderungen (helpers.php) ───────────────────────────────────────
    'req.php'             => 'PHP ≥ 8.4',
    'req.php_detail'      => 'Installiert: %s',
    'req.ext'             => 'PHP-Erweiterung: %s',
    'req.loaded'          => 'Geladen ✓',
    'req.missing'         => 'FEHLT',
    'req.ok'              => 'OK',
    'req.not_writable'    => 'Nicht beschreibbar!',
    'req.config_writable' => 'configs/-Verzeichnis beschreibbar',
    'req.db_driver'       => 'Datenbank-Treiber',
];

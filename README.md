# TowerDNS

TowerDNS ist ein providerunabhaengiges, DNS-zentriertes Management-Panel und der offizielle technische Nachfolger von desec-manager.

## Vorgaenger und Migration

Vorgaengerprojekt:
- Selfhosted: https://git.diath.systems/DiamantTh/desec-manager
- GitHub: https://github.com/DiamantTh/desec-manager

Das bisherige Projekt desec-manager dient als Migrationsbasis. Die Weiterentwicklung erfolgt ausschliesslich in diesem Repository.

## Zielbild

TowerDNS ist kein reiner Wrapper fuer einen einzelnen Anbieter. Das Projekt stellt eine gemeinsame Kernlogik fuer DNS-Verwaltung bereit und bindet Provider ueber eine abstrahierte, capability-orientierte Schnittstelle an.

Schwerpunkte:
- Verwaltung von Zonen, Records, TTL, Kommentaren, Tags und Metadaten
- Striktes Rollen- und Rechtesystem mit zentraler Pruefung in der Application-Schicht
- DNSSEC als eigener fachlicher Bereich, nicht als Sonderbehandlung am Rand
- Provideradapter fuer deSEC, PowerDNS, Cloudflare und INWX

## Architektur

Layer:
- UI: einheitliche Oberflaeche ohne direkte Anbieterlogik
- Application/Core: providerneutrale Workflows, Validierung, Normalisierung und Rechtepruefung
- Provider-Abstraktion: gemeinsamer Vertrag und capability-orientiertes Modell
- Provider-Implementierungen: deSEC, PDNS, Cloudflare, INWX als getrennte Adapter
- Domain-Modell: kanonische Darstellung von DNS-Objekten inklusive DNSSEC

## DNSSEC-Grundsatz

DNSSEC wird fachlich vollstaendig modelliert:
- Provider mit einfacher/automatischer DNSSEC-Abwicklung werden korrekt abgebildet
- Provider mit direkter DNSSEC-Steuerung bleiben detailliert steuerbar
- PowerDNS DNSSEC-Funktionen werden direkt an die PDNS-API angebunden

## Rollen und Rechte

TowerDNS fuehrt ein feingranulares Berechtigungssystem ein:
- getrennte Rechte fuer Lesen, Anlegen, Aendern, Loeschen
- getrennte Rechte fuer DNSSEC-Aktionen
- getrennte Rechte fuer Provider-Credentials, Rollen/User und globale Admin-Funktionen

Rechtepruefungen sind zentral im Core und nicht nur in der UI.

## Technische Basis

TowerDNS uebernimmt den Stack des Vorgaengerprojekts desec-manager und entwickelt ihn provider-neutral weiter:

- PHP >= 8.4, Composer (PSR-4 unter `TowerDNS\`)
- HTTP-Schicht: Mezzio (PSR-15) + FastRoute + PHP-DI
- UI-Renderer: Svelte-Anwendungsshell mit sicherem JSON-Bootstrap (ohne Twig)
- Sessions/CSRF: `mezzio-session`, `mezzio-session-ext`, `mezzio-csrf`
- Validierung/Filter/Inputs: Laminas (`laminas-filter`, `laminas-validator`, `laminas-inputfilter`, `laminas-i18n`)
- RBAC-Bibliothek: `laminas/laminas-permissions-rbac` (eigene Permission/Role-Domain dazu)
- Persistenz: Doctrine DBAL (^3.7) wie in desec-manager
- HTTP-Clients: Guzzle 7
- Logging/Telemetrie: Monolog 3, Sentry 4
- Caching: Symfony Cache, PSR Simple Cache
- CLI/Mailer/Konfig: Symfony Console, Symfony Mailer, `yosymfony/toml`
- Authentifizierung: WebAuthn (`web-auth/webauthn-lib`), TOTP (`spomky-labs/otphp`), Passwortpruefung (`bjeavons/zxcvbn-php`)
- Frontend: Skeleton 5 + Svelte 5 + Tailwind CSS 4 + Vite 6 + TypeScript
- Tests/Statisch: PHPUnit 11, PHPStan 2 (Level 8)
- Saubere Layer-Struktur unter `src/Domain`, `src/Application`, `src/Infrastructure`, `src/UI`

## Aktueller Stand

- providerneutrales Vertragsmodell mit `ProviderRegistry` und `Capability`-Konstanten
- kanonisches DNS- und DNSSEC-Modell (`Zone`, `Record`, `DnssecProfile`, `DnssecState`)
- RBAC mit zentraler Durchsetzung im `DnsManagementService` (Permission + Capability)
- Eingabevalidierung mit IDN-Normalisierung (`DnsNameValidator`) und Record-Pruefung (`RecordValidator`)
- deSEC-Adapter funktional aus desec-manager portiert (`DeSECApiClient`, `DeSECProvider`)
- PowerDNS-Adapter inkl. nativer DNSSEC-Steuerung (`cryptokeys`-API)
- Cloudflare- und INWX-Adapter als Skelette mit korrekt deklarierten Capabilities
- PHPUnit- und PHPStan-Konfiguration (Level 8), erste Tests fuer Validierung und Service
- AGPL-3.0-or-later, durchgaengig SPDX-Header in allen PHP-Dateien

## Entwicklung

```
composer install
composer check       # lint + phpstan + phpunit
npm install
npm run check
npm run build        # produktionsfertige Assets -> httpdocs/assets/
```

Die gebauten Assets werden mit dem Release ausgeliefert; auf dem Zielsystem
ist deshalb kein Node.js erforderlich. Mezzio liefert Seitendaten und
CSRF-geschuetzte Endpunkte; Svelte rendert die gesamte Anwendung.

## Themes

Themes liegen unter `themes/<name>/theme.json`. Das Manifest ordnet das
TowerDNS-Theme einem gebuendelten Skeleton-Theme zu. Markup und Verhalten
bleiben zentral in Svelte; Themes variieren die Skeleton-Design-Tokens.

Das globale Theme wird bei der Installation oder in den Systemeinstellungen
aus den validierten Manifesten gewaehlt. Benutzer koennen im Profil dieses
Theme erben (`system`) oder eines der installierten Themes auswaehlen.

Weiterfuehrende Dokumentation:
- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)
- [docs/RBAC.md](docs/RBAC.md)
- [docs/MIGRATION_FROM_DESEC_MANAGER.md](docs/MIGRATION_FROM_DESEC_MANAGER.md)

## Naechste Schritte

- Cloudflare- und INWX-Adapter ausimplementieren
- Persistenz fuer Provider-Konfigurationen, User und Rollen anbinden
- HTTP-/UI-Layer auf den Application-Services aufsetzen
- Importpfad aus desec-manager-Datenbestaenden bereitstellen

## Lizenz

GNU Affero General Public License v3.0 or later (AGPL-3.0-or-later), siehe [LICENSE](LICENSE).

SPDX-Identifier: `AGPL-3.0-or-later`.

TowerDNS ist der technische Nachfolger von desec-manager und uebernimmt dessen Copyleft-Charakter konsequent: Wer eine modifizierte Version als Netzwerkdienst betreibt, muss den entsprechenden Quellcode den Nutzern zugaenglich machen (AGPL §13).

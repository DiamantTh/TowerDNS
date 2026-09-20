# TowerDNS

TowerDNS ist eine eigenständige, self-hosted DNS-Verwaltungsanwendung. Ein gemeinsamer Anwendungskern verwaltet Accounts, Zonen und DNS-Records und bindet DNS-Provider über capability-geprüfte Adapter an.

## Zielbild

TowerDNS ist kein reiner Wrapper fuer einen einzelnen Anbieter. Das Projekt stellt eine gemeinsame Kernlogik fuer DNS-Verwaltung bereit und bindet Provider ueber eine abstrahierte, capability-orientierte Schnittstelle an.

Schwerpunkte:
- Verwaltung von Account-gebundenen DNS-Zonen und RRsets
- TTL- und RDATA-Validierung vor Provider-Mutationen
- Rollen- und Rechtesystem mit zentraler Prüfung in der Application-Schicht
- DNSSEC-Status und nur die Aktionen, die der jeweilige Adapter tatsächlich unterstützt
- getrennte Provider-Verbindungen pro Account sowie systemweite Provider-Konfiguration

## Architektur

Layer:
- UI: Svelte 5, TypeScript und Tailwind; keine Provider-API-Aufrufe aus dem Browser
- Application: providerneutrale Workflows, Eingabevalidierung und Berechtigungsprüfung
- Provider-Verträge: gemeinsame Interfaces und Capability-Modell
- Provider-Module: getrennte Adapter und anbieterbezogene Eingabe-/Credential-Schemata
- Domain: Account-gebundene Ressourcen sowie DNS-, RRset- und DNSSEC-Wertmodelle

## DNSSEC-Grundsatz

Der DNSSEC-Umfang ist providerabhängig. TowerDNS zeigt den Status, wenn ein Adapter ihn anbietet, und bietet manuelle Aktionen nur an, wenn dessen Capability dies erlaubt. Automatische DNSSEC-Verwaltung, DS-Daten und Schlüsseloperationen sind nicht bei jedem Provider verfügbar.

## Rollen und Rechte

TowerDNS fuehrt ein feingranulares Berechtigungssystem ein:
- getrennte Rechte fuer Lesen, Anlegen, Aendern, Loeschen
- getrennte Rechte fuer DNSSEC-Aktionen
- getrennte Rechte fuer Provider-Credentials, Rollen/User und globale Admin-Funktionen

Rechtepruefungen sind zentral im Core und nicht nur in der UI.

## Technische Basis

- PHP >= 8.4, Composer (PSR-4 unter `TowerDNS\`)
- HTTP-Schicht: Mezzio (PSR-15) + FastRoute + PHP-DI
- UI-Renderer: Svelte-Anwendungsshell mit sicherem JSON-Bootstrap (ohne Twig)
- Sessions/CSRF: `mezzio-session`, `mezzio-session-ext`, `mezzio-csrf`
- Validierung/Filter/Inputs: Laminas (`laminas-filter`, `laminas-validator`, `laminas-inputfilter`, `laminas-i18n`)
- RBAC-Bibliothek: `laminas/laminas-permissions-rbac` (eigene Permission/Role-Domain dazu)
- Persistenz und kontrollierte Schema-Upgrades: Doctrine DBAL 3 und Doctrine Migrations
- HTTP-Clients: Guzzle 7
- Logging/Telemetrie: Monolog 3, Sentry 4
- Caching: Symfony Cache, PSR Simple Cache
- CLI/Mailer/Konfig: Symfony Console, Symfony Mailer, `yosymfony/toml`
- Authentifizierung: WebAuthn (`web-auth/webauthn-lib`), TOTP (`spomky-labs/otphp`), Passwortpruefung (`bjeavons/zxcvbn-php`)
- Frontend: Skeleton 5 + Svelte 5 + Tailwind CSS 4 + Vite 6 + TypeScript
- Tests/Statisch: PHPUnit 11, PHPStan 2 (Level 8)
- Provider-Module unter `modules/`; Kern-Layer unter `src/Domain`, `src/Application` und `src/Infrastructure`

## Aktueller Stand

- providerneutrale Workflows über `ManagedZoneDNSService`, Provider-Factory und Capability-Prüfungen
- Account-gebundene Zonen, Provider-Verbindungen und serverseitige Session-/CSRF-geschützte Verwaltungsseiten
- Record- und RRset-Operationen mit providerseitigem Read-back für RRset-Ersetzungen
- Provideradapter unterschiedlicher Reifegrade; siehe [Provider-Funktionsmatrix](docs/PROVIDER_CAPABILITIES.md)
- automatisierte PHPUnit-Tests für DNS-Validierung und simulierte Provider-API-Antworten
- AGPL-3.0-or-later; konkrete Codeherkunft ist in [docs/PROJECT_HISTORY.md](docs/PROJECT_HISTORY.md) festgehalten

## Entwicklung

```
composer install
composer check       # lint + phpstan + phpunit
npm install
npm run check
npm run build        # Frontend-Assets -> httpdocs/assets/
```

Der Frontend-Build wird für die lokale Anwendung und die Entwicklungsprüfung
nach `httpdocs/assets/` geschrieben. Mezzio liefert Seitendaten und
CSRF-geschuetzte Endpunkte; Svelte rendert die gesamte Anwendung.

Technische Voraussetzungen, der sichere DocumentRoot, der Browser-Installer
und der Betrieb auf PHP-FPM bzw. klassischem Shared Hosting sind in
[docs/INSTALLATION.md](docs/INSTALLATION.md) beschrieben. Die Anleitung
unterscheidet zwischen dem Entwicklungscheckout und einem späteren, außerhalb
dieses Repositories zu erstellenden Deployment-Paket.

## Kommandozeile

TowerDNS stellt seine Verwaltungsbefehle über `bin/towerdns` bereit. Der
Einstiegspunkt ist außerdem als Composer-Binary deklariert, sodass er bei einer
Verwendung als Abhängigkeit unter `vendor/bin/towerdns` verfügbar ist.
Die Commands werden dabei über denselben PHP-DI-Container wie die HTTP-Anwendung
aufgelöst.

```bash
php bin/towerdns list --format=toml # außerdem: txt, json, xml, md
php bin/towerdns              # interaktive Installation
php bin/towerdns towerdns:user:password-reset admin@example.org --generate
php bin/towerdns towerdns:schema:status
php bin/towerdns towerdns:schema:validate
php bin/towerdns towerdns:schema:migrate --confirm
php bin/towerdns zone:list desec
php bin/towerdns record:list desec example.org --format=json | jq '.records[]'
```

`record:list` erwartet stets den Zonennamen. TowerDNS löst ihn beim Provider
auf dessen technische Zonen-ID auf; diese wird nur im strukturierten Output
(`--format=json` oder `--format=toml`) ausgegeben.

Globale Optionen: `-h`/`--help`, `-v`/`-V`/`--version` sowie `--verbose`.

`php install/install-cli.php` bleibt für bestehende Installationsanleitungen als
Weiterleitung erhalten.

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
- [docs/PROVIDER_CAPABILITIES.md](docs/PROVIDER_CAPABILITIES.md)
- [docs/ui-examples/README.md](docs/ui-examples/README.md)
- [docs/PROJECT_HISTORY.md](docs/PROJECT_HISTORY.md)

## Lizenz

GNU Affero General Public License v3.0 or later (AGPL-3.0-or-later), siehe [LICENSE](LICENSE).

SPDX-Identifier: `AGPL-3.0-or-later`.

Die Lizenzbedingungen und die Herkunft einzelner übernommener Bestandteile sind in [LICENSE](LICENSE) und [docs/PROJECT_HISTORY.md](docs/PROJECT_HISTORY.md) beschrieben.

# TowerDNS

TowerDNS ist ein selbst gehostetes Control Panel zur Verwaltung von DNS-Zonen
über mehrere Provider. Es ist weder ein autoritativer oder rekursiver
DNS-Server noch ein allgemeines Hosting-Panel.

## Funktionen

- Account-gebundene Zonen und Provider-Verbindungen
- Records und RRsets mit gemeinsamer Validierung und providerabhängigen
  Fähigkeiten
- Rollen, Berechtigungen, Audit-Logs und Account-Mitgliedschaften
- DNSSEC-Status und Aktionen nur dort, wo der jeweilige Adapter sie unterstützt
- Anmeldung mit Passwort, TOTP und WebAuthn/Passkeys

Enthaltene Provider-Module: Cloudflare, ClouDNS, deSEC, Google Cloud DNS,
INWX, netcup, OVHcloud und PowerDNS. Unterstützte Operationen unterscheiden
sich je Provider; maßgeblich sind die Capability-Angaben im jeweiligen
`modules/<Provider>/`-Adapter.

## Installation

Für eine vorbereitete Distribution werden auf der Build-Maschine Composer,
Node.js 22.15+ und npm benötigt:

```sh
composer install
npm ci
npm run package:distribution
```

Das Archiv unter `dist/` enthält Produktions-PHP-Abhängigkeiten sowie das
gebaute, geprüfte Frontend samt Manifest und Brotli-/Zstandard-Dateien. Auf dem
Zielsystem werden weder Composer noch Node.js, Root-Rechte oder laufende Worker
benötigt.

Runtime: PHP 8.4.3+ mit `intl`, `json`, `mbstring`, `openssl`, `pdo` und
`sodium`; zusätzlich `pdo_sqlite`, `pdo_mysql` oder `pdo_pgsql` für die
gewählte Datenbank. SQLite, MariaDB/MySQL und PostgreSQL werden unterstützt.

Das Paket außerhalb des öffentlichen Webroots entpacken und den Webserver auf
`httpdocs/` zeigen lassen. `configs/`, `cache/`, `data/` und `logs/` müssen für
PHP beschreibbar sein; `install/` nur während der Einrichtung. HTTPS wird
dringend empfohlen und ist für einen sicheren Betrieb praktisch erforderlich.
Kann ein Hosting-Angebot weder `httpdocs/` als
DocumentRoot noch eine gleichwertig sichere Trennung bereitstellen, ist es
nicht geeignet. Installation im Browser: `/install.php`; danach wird der
Installer gesperrt. Der CLI-Installer nutzt denselben Bootstrap-Unterbau.

Vor Schema-Upgrades Datenbank und `configs/config.local.toml` gemeinsam
sichern. Darin liegt unter anderem der Schlüssel für verschlüsselte Provider-
und TOTP-Daten. Migrationen kontrolliert mit `towerdns:schema:status`,
`towerdns:schema:validate` und `towerdns:schema:migrate --confirm` ausführen;
nach einem Update `/ready` prüfen.

## Entwicklung und Prüfungen

```sh
composer install
npm ci
npm run frontend:check
php scripts/activate-frontend.php
composer check
```

`composer check` umfasst PHP-Lint, Format-/Style-Prüfungen, Rector, PHPStan,
Psalm und PHPUnit. Browser-/Datenbank-Integrationstests stehen unter
`tests/Frontend/` und `tests/Integration/`; einige davon benötigen Firefox
bzw. Podman.

## CLI

```sh
php bin/towerdns list
php bin/towerdns towerdns:schema:status
php bin/towerdns towerdns:schema:validate
php bin/towerdns towerdns:schema:migrate --confirm
php bin/towerdns zone:list desec
php bin/towerdns record:list desec example.org --format=json
```

## Aufbau und Herkunft

`src/Domain` enthält Fachmodelle, `src/Application` Workflows und
Berechtigungsprüfungen, `src/Infrastructure` HTTP, DBAL-Persistenz und CLI.
Provider-Adapter leben in `modules/`; das Frontend verwendet Svelte 5 und Vite.
Die Datenbankzugriffe verwenden Doctrine DBAL und versionierte Migrationen.

Screenshots: [`docs/ui-examples/`](docs/ui-examples/README.md). Eine kurze
Provenienznotiz zu übernommenem Code steht in
[`docs/PROJECT_HISTORY.md`](docs/PROJECT_HISTORY.md).

## Lizenz

AGPL-3.0-or-later. SPDX-Identifier: `AGPL-3.0-or-later`.

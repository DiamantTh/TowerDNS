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
Installer gesperrt. Der CLI-Installer wird mit `php install/install-cli.php` oder
`php bin/towerdns towerdns:install` ausdrücklich gestartet und nutzt denselben
Bootstrap-Unterbau. `php bin/towerdns` zeigt die Befehlsübersicht.

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
php bin/towerdns towerdns:upgrade:check
php bin/towerdns towerdns:upgrade:check --format=json
php bin/towerdns towerdns:schema:status
php bin/towerdns towerdns:schema:validate
php bin/towerdns towerdns:schema:migrate --confirm
php bin/towerdns zone:list desec
php bin/towerdns record:list desec example.org --format=json
```

Der [Betriebsleitfaden](docs/OPERATIONS.md) enthält die Release-Checkliste,
Beispiele für `towerdns:backup:create|verify|restore` und die Einrichtung von
`towerdns:monitor:run` und `towerdns:monitor:notify` per Cron oder systemd.
Der zonengebundene Statusabruf verwendet den eigenen API-Scope `monitoring:read`.
Reproduzierbare Datenbank- und Restore-Proben stehen in der
[Integrationsdokumentation](tests/Integration/README.md).

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

### TLSA und DANE

`php bin/towerdns tlsa:generate example.org 443 bundle.pem --usage=3 --selector=1 --matching=1`
gibt TLSA-Zeilen für die passenden öffentlichen Zertifikate aus. Der
Generator unterscheidet CA- und Endzertifikate. Die Webvorschau zeigt Gültigkeitszeitraum, DNS-SAN-Vergleich und
OpenSSL-Kettenprüfung gegen den System-Trust-Store getrennt an. Hochgeladene
CA-Zertifikate werden nur als Zwischenzertifikate berücksichtigt. Der
Generator bestätigt weder DNSSEC noch die DANE-Authentifizierung eines Dienstes.

Die Profilseite stellt auf HTTPS 90 Tage gültige Bearer-Schlüssel aus.
Lesezugriff kann auf eine Zone beschränkt werden. Schreibschlüssel gelten
für genau einen Dienst-Owner innerhalb einer Zone. Die aktuellen Benutzerrechte
gelten zusätzlich. Der Zugriff `tlsa:append` kann Werte hinzufügen, aber keine
Werte entfernen. Für die Rotation einen neuen Schlüssel anlegen, den
Client umstellen und den alten Schlüssel widerrufen.

- `GET /api/v1/accounts/{account}/zones/{zone}/tlsa` listet TLSA-RRsets
  für einen zonenweiten Leseschlüssel.
- `GET …/tlsa?owner=_443._tcp` liefert die Werte, TTL, das Merkmal
  `conditional`, konkrete `capabilities` und den `ETag`. Bei einem dienstgebundenen Schlüssel
  kann der Owner entfallen.
- `POST …/tlsa?owner=_443._tcp` akzeptiert JSON mit `add` und/oder
  `remove` als Listen exakter RDATA-Werte und den gelesenen `ETag` im
  Header `If-Match`. Alternativ oder zusätzlich kann `certificates`
  ein PEM-Bündel mit den numerischen Feldern `usage`, `selector`
  und `matching` enthalten. `ttl` gilt nur beim Erstellen.
  HTTP 412 bedeutet, dass der gelesene ETag bereits vor dem Schreibversuch
  veraltet war. HTTP 409 meldet fehlende Provideroperation oder einen
  zwischenzeitlichen Providerkonflikt. Jede API-Anfrage benötigt
  `Authorization: Bearer …`.

Der geschützte TLSA-Pfad verwendet je nach Adapter dessen tatsächliche
Schreibmöglichkeiten:

| Adapter | TLSA-Änderung | Zustandsprüfung / Garantie |
| --- | --- | --- |
| Google Cloud DNS | RRset ersetzen | TowerDNS vergleicht den aktuellen Providerzustand; Google übernimmt die Änderung als atomare Change-Transaktion. DNS-Propagation bleibt davon getrennt. |
| Cloudflare | einzelne Records anlegen/löschen über native Record-IDs | Geschwister bleiben unberührt; kein Provider-CAS, Mehrfachänderungen sind mehrere Requests und DNS-Propagation ist nicht atomar. |
| ClouDNS, INWX, OVHcloud | einzelne Records über die vorhandenen CRUD-Endpunkte | gezielte Änderungen ohne RRset-Ersetzung; kein CAS und keine Atomarität über mehrere Requests. |
| PowerDNS (System-/Administrationsadapter) | `EXTEND`/`PRUNE` bei erkanntem Authoritative 4.9.12+ oder 5.0.2+ | einzelne Werte werden serverseitig gezielt ergänzt/entfernt; kein CAS und kein atomarer Mehrschritt-Rollover. Ältere Versionen werden für den TLSA-Delta-Pfad abgelehnt. |
| deSEC | vorhandene RRset-Endpunkte | Bulk-PATCH/PUT kann mehrere RRsets als Request atomar annehmen/veröffentlichen, aber `records` ersetzt das Wertefeld und die API dokumentiert kein erwartetes Vorher-Bild/If-Match. Kein gezieltes Einzelwert-CAS; der Delta-/Rollover-Pfad bleibt abgelehnt. |
| netcup | Zonen-Recordliste wird als Ganzes geschrieben | kein bedingter oder gezielter Einzelrecord-Pfad; der Delta-/Rollover-Pfad bleibt abgelehnt. |

Die Anzeige unterscheidet gezielte Einzelwertoperationen von bedingtem
RRset-Ersetzen. ETags erkennen Änderungen, die vor dem TowerDNS-Schreibpfad
bereits sichtbar sind. Nur ein Providerverfahren, das den erwarteten Zustand
beim Schreiben selbst atomar prüft, verhindert zusätzlich externe
Read-Modify-Write-Rennen. Wo der Provider nur einzelne Records anbietet,
werden deswegen ausschließlich die angeforderten Werte bearbeitet; bei
Netzwerkabbrüchen liest TowerDNS erneut und wiederholt niemals blind ein
vollständiges RRset-Ersetzen.

Für einen Rollover die neuen Werte zuerst hinzufügen und die
Veröffentlichung am autoritativen DNS sowie bei Bedarf über einen
validierenden Resolver prüfen. Vor der Umstellung des TLS-Dienstes
mindestens die vorherige TTL berücksichtigen. Alte Werte erst nach der
Umstellung und Prüfung gezielt entfernen. Ein beim Provider bestätigtes
RRset allein belegt keine erfolgreiche DNSSEC- oder DANE-Validierung.

Die externe ACME-Nutzung, API-Schlüsselrotation und ein gestufter
Prepublication-Rollover sind in
[`docs/TLSA_EXTERNAL_AUTOMATION.md`](docs/TLSA_EXTERNAL_AUTOMATION.md)
beschrieben; ein minimales Beispielskript liegt unter
[`docs/examples/tlsa-api.sh`](docs/examples/tlsa-api.sh).

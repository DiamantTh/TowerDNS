# TowerDNS installieren und betreiben

Diese Anleitung beschreibt den unterstützten Weg für ein fertiges TowerDNS-
Release auf Shared Hosting sowie den Betrieb mit PHP-FPM. Das Release enthält
bereits den Composer-Autoloader und die kompilierten Browser-Assets. Auf dem
Zielhost sind deshalb weder Composer noch Node.js erforderlich.

## Voraussetzungen

TowerDNS benötigt PHP 8.4 oder neuer. PHP 8.4 ist zum Zeitpunkt dieser
Version eine unterstützte PHP-Version; die aktuelle Support-Matrix steht in der
[offiziellen PHP-Übersicht](https://www.php.net/supported-versions.php).

Immer erforderlich sind die PHP-Erweiterungen `intl`, `json`, `mbstring`,
`openssl`, `pdo` und `sodium` sowie Sessions. Für die gewählte Datenbank muss
zusätzlich der passende PDO-Treiber installiert sein:

* MySQL oder MariaDB: `pdo_mysql`
* PostgreSQL: `pdo_pgsql`
* SQLite: `pdo_sqlite`

Doctrine DBAL verwendet genau diese PDO-Treiber; die unterstützten Treiber und
Verbindungsparameter sind in der [DBAL-Dokumentation](https://www.doctrine-project.org/projects/doctrine-dbal/en/3.3/reference/configuration.html)
beschrieben. Der Installer bietet alle drei Treiber an, die automatisierten
Tests in diesem Repository verwenden SQLite. Live-Tests gegen MySQL/MariaDB
und PostgreSQL sind nicht Teil des lokalen Testlaufs und müssen vor einem
Produktiv-Rollout mit der Provider-Datenbank geprüft werden.

Der PHP-Prozess benötigt Schreibrechte für die privaten Verzeichnisse
`configs/`, `cache/`, `data/` und `logs/`. `data/` wird bei SQLite auch für die
Datenbank verwendet. HTTPS wird dringend empfohlen und ist für den sicheren
Betrieb von Passwörtern, Sessions und Einladungstokens praktisch erforderlich.
OPcache ist optional, aber empfehlenswert. Cronjobs, SSH, Composer, Node.js,
Shell-Zugriff, Root-Rechte und dauerhaft laufende Worker sind für den normalen
Webbetrieb nicht erforderlich. SMTP ist optional; ohne Mailer-Konfiguration
können keine Einladungs- oder Reset-E-Mails zugestellt werden.

## Release bauen

Auf dem Entwicklungs- oder Build-Rechner:

```sh
npm ci
npm run release:build
```

Der Build führt den Vite-Produktionsbuild aus und ruft anschließend
`php bin/build-release.php` auf. Das Skript installiert mit
`composer install --no-dev --prefer-dist --optimize-autoloader` nur
Produktionsabhängigkeiten und erzeugt `dist/towerdns-<version>.zip`.
Diese Composer-Optionen entsprechen dem empfohlenen Deployment-Ablauf in der
[offiziellen Composer-Dokumentation](https://getcomposer.org/doc/03-cli.md).

Die Versionsnummer kommt aus `VERSION` oder aus `--version=...`. Für einen
lokalen Build ohne `sodium` kann vorübergehend
`--ignore-platform-req=ext-sodium` verwendet werden; ein solches Archiv darf
nicht als Produktionsrelease verteilt werden. Produktionsbuilds müssen alle
Plattformanforderungen erfüllen.

Ein Release enthält unter anderem:

* `httpdocs/` mit `index.php`, `install.php`, `.htaccess`, Favicon und gebauten
  Assets,
* `src/`, `modules/`, `templates/`, `translations/`, Theme-Manifeste und
  `install/`,
* `vendor/` mit Produktionsabhängigkeiten,
* `bin/towerdns`, `VERSION`, `LICENSE` und diese Anleitung.

Nicht enthalten sind Git-Metadaten, Tests, Node-Module, Theme-Quellcode,
Entwicklungsdateien, Composer-Dateien, lokale Konfigurationen, Logs, Cache,
Datenbanken, Backups, Schlüssel und Zugangsdaten. Die gebauten Dateien unter
`httpdocs/assets/` sind absichtlich Teil des Archivs.

## Shared Hosting ohne SSH, Composer oder Node.js

1. Ein fertiges ZIP-Release herunterladen und lokal entpacken.
2. Den gesamten Release-Ordner per FTP/SFTP oder Hosting-Dateimanager in den
   privaten Webspace hochladen.
3. Den **DocumentRoot ausschließlich auf `release/httpdocs`** setzen. Die
   Verzeichnisse `src/`, `vendor/`, `configs/`, `data/`, `logs/` und `install/`
   müssen außerhalb des öffentlichen Verzeichnisses bleiben.
4. Beim Hosting-Provider PHP 8.4+ und den benötigten PDO-Treiber aktivieren
   und eine Datenbank samt Benutzer anlegen.
5. `https://example.org/install.php` aufrufen und Datenbank-, Admin-,
   Domain- und Providerdaten eingeben.
6. Nach erfolgreicher Einrichtung mit dem gewählten Admin-Konto anmelden und
   die vorgeschlagenen Mail- und Sicherheitsoptionen prüfen.

Der Installer legt die Konfiguration atomar an, erstellt das Schema und schreibt
`configs/.installed` außerhalb des DocumentRoots. Der erste Browserzugriff
initialisiert den Installer-Token für die laufende Session; ein späterer Zugriff
aus einer anderen Session kann ohne diesen Dateisystem-Token nicht fortfahren.
Nach dem Abschluss kann der Installer über den Button entfernt werden. Ein
erneuter Aufruf erkennt die persistente Installationsmarke und startet keine
Neuinstallation.
Bei älteren Installationen ohne diese Marke werden die drei vollständigen
Bootstrap-Dateien `configs/config.local.toml`, `configs/database.toml` und
`configs/providers.toml` nur dann als kompatibler Abschlusszustand erkannt,
wenn der alte Installer bereits entfernt wurde. Solange `install/` vorhanden
ist, bleibt ein abgebrochener Lauf mit denselben Dateien erneut versuchbar; die
Dateien werden dabei nicht verändert.

Wenn ein Hosting-Panel keinen frei wählbaren DocumentRoot und keine sichere
Regel zum Abschotten privater Verzeichnisse bietet, ist eine reine FTP-
Installation nicht sicher unterstützbar. Das komplette Repository öffentlich
abzulegen und einzelne Dateien nur per Rewrite zu verstecken ist kein
gleichwertiger Ersatz. Für Apache liegt eine defensive `.htaccess` im
DocumentRoot; bei Nginx muss der Provider eine `try_files`-Weiterleitung auf
`index.php` und den Schutz nichtöffentlicher Pfade konfigurieren. Mezzio geht
ebenfalls von einem separaten öffentlichen Verzeichnis aus; siehe die
[Standalone-Dokumentation](https://docs.mezzio.dev/mezzio/v1/getting-started/standalone/)
und den Hinweis zu Basis-Pfaden bei Unterverzeichnissen in der
[Mezzio-Dokumentation](https://docs.mezzio.dev/mezzio/v3/cookbook/using-a-base-path/).

## Webinstaller und Fehlerfälle

Der Installer prüft PHP, Pflicht-Erweiterungen, Schreibrechte und den gewählten
PDO-Treiber, bevor er den jeweiligen Schritt zulässt. Datenbankfehler werden
protokolliert, aber nicht mit Zugangsdaten oder rohen Exception-Texten im
Browser ausgegeben. Fehlende Erweiterungen, nicht beschreibbare Verzeichnisse,
ungültige Datenbankdaten und verlorene Wizard-Sessions können korrigiert und
erneut versucht werden.

Das Administratorpasswort wird nach dem Speichern nicht in der Erfolgsseite,
Session-Ausgabe oder einem Log angezeigt. Bei einem Fehler werden keine
Konfigurationsdateien als Erfolg markiert. Die Schemaerzeugung ist additiv und
idempotent; bereits vorhandene Benutzer, Konten und Ressourcen werden nicht
überschrieben.

## Updates und Backups

Vor einem Update Datenbank, `configs/*.toml` (einschließlich des
Verschlüsselungsschlüssels) und bei SQLite die Datei unter `data/` sichern.
Neue Releases sollten in ein neues privates Verzeichnis entpackt werden. Die
gesicherten Konfigurationen und Laufzeitdaten werden übernommen und der
DocumentRoot anschließend auf das neue `httpdocs/` umgeschaltet.

Beim ersten Request gleicht `SchemaManager` fehlende Tabellen und bekannte
Spalten additiv ab. TowerDNS enthält derzeit keine allgemeine, versionierte
Doctrine-Migrationsverwaltung; destruktive oder datenverändernde Migrationen
werden daher nicht automatisch ausgeführt. Bei größeren Schemaänderungen ist
ein vorheriger Datenbankdump und ein getesteter Upgrade-Schritt erforderlich.
Eine vorhandene Installation wird niemals stillschweigend mit einem frischen
Schema überschrieben.

## Einladungsregistrierung

Einladungen zu Organisationskonten sind keine offene Registrierung. Eine gültige,
noch nicht abgelaufene Einladung enthält einen einmaligen Token, der nur als
Hash gespeichert wird. Neue Benutzer öffnen den Einladungslink, setzen ihr
Passwort und werden zentral über denselben User-Lifecycle angelegt wie andere
Benutzer. Dabei entstehen der persönliche Account und die Membership atomar;
Rolle, Account-Status und Mitgliederlimit werden im Abschlussmoment erneut
geprüft. Bestehende Benutzer melden sich zunächst an und nehmen die Einladung
anschließend an. Abgelaufene, widerrufene, bereits verwendete oder an eine
andere E-Mail-Adresse gebundene Tokens werden abgewiesen.

## PHP-FPM/VPS und lokale Entwicklung

Bei PHP-FPM wird `httpdocs/` als Nginx- oder Apache-DocumentRoot konfiguriert;
private Verzeichnisse liegen daneben und gehören dem PHP-FPM-Benutzer. HTTPS,
OPcache, restriktive Dateirechte und eine externe Datenbanksicherung werden
empfohlen. Ein Cronjob ist für den normalen Request-Betrieb nicht notwendig.

Lokal sind Composer- und Node.js-Entwicklungsabhängigkeiten erforderlich:

```sh
composer install
npm ci
npm run frontend:check
composer check
```

Die Release-Prüfung ist auf PHP 8.4+ mit den Pflicht-Erweiterungen und einer
funktionierenden Composer-/Node-Toolchain ausgelegt. In einer Sandbox ohne
`sodium` kann der Anwendungstest weiterlaufen, aber ein echter
Produktions-Composer-Check bleibt zu Recht rot; dieses Umgebungsdefizit darf
nicht als Produktionskompatibilität gewertet werden.

## Aktuelle Prüfgrenzen

Automatisiert werden SQLite-Schema, Installer-nahe PHP-Pfade, Anwendungstests,
statische Analysen und die Frontend-Assets geprüft. Ein echter Browserlauf auf
jedem Shared-Hosting-Panel, SMTP-Zustellung sowie Live-Verbindungen zu
MySQL/MariaDB und PostgreSQL sind Umgebungs- bzw. Providerprüfungen und müssen
vor dem jeweiligen Rollout separat bestätigt werden.

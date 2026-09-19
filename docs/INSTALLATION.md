# TowerDNS installieren und betreiben

Diese Anleitung beschreibt die technischen Voraussetzungen und den aktuellen
Installationsweg von TowerDNS. Der Repository-Checkout ist für Entwicklung
und Tests ausgelegt; eine spätere Distribution kann außerhalb des Repositories
über einen CI/CD-Workflow erstellt werden. Dieses Repository enthält keine
eigene Release-Paketierung.

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
Rollout mit der Provider-Datenbank geprüft werden.

Der PHP-Prozess benötigt Schreibrechte für die privaten Verzeichnisse
`configs/`, `cache/`, `data/` und `logs/`. `data/` wird bei SQLite auch für die
Datenbank verwendet. HTTPS wird dringend empfohlen und ist für den sicheren
Betrieb von Passwörtern, Sessions und Einladungstokens praktisch erforderlich.
OPcache ist optional, aber empfehlenswert. Cronjobs, SSH, Composer, Node.js,
Shell-Zugriff, Root-Rechte und dauerhaft laufende Worker sind für den normalen
Webbetrieb nicht erforderlich. SMTP ist optional; ohne Mailer-Konfiguration
können keine Einladungs- oder Reset-E-Mails zugestellt werden.

## Entwicklungscheckout

Für lokale Entwicklung werden Composer- und Node.js-Abhängigkeiten benötigt:

```sh
composer install
npm ci
npm run frontend:check
composer check
```

`npm run build` erzeugt die für den Browser benötigten Frontend-Assets unter
`httpdocs/assets/`. Dieser normale Entwicklungsbuild erzeugt keine Distribution.
Der Checkout enthält absichtlich keine eigene Paketierungs- oder
Veröffentlichungslogik.

## Webroot und Dateisystem

Der öffentliche DocumentRoot muss auf `httpdocs/` zeigen. Öffentlich benötigt
werden dort nur der Front-Controller, Installer-Einstieg, Favicon und die
gebauten Assets. `src/`, `modules/`, `templates/`, `translations/`, `vendor/`,
`configs/`, `data/`, `cache/`, `logs/`, `tests/` und `install/` müssen außerhalb
des öffentlichen Webroots liegen oder vom Webserver zuverlässig gesperrt sein.

Für Apache liegt eine defensive `.htaccess` in `httpdocs/`. Bei Nginx muss der
Provider eine `try_files`-Weiterleitung auf `index.php` und den Schutz der
nichtöffentlichen Pfade konfigurieren. Wenn ein Hosting-Panel keinen frei
wählbaren DocumentRoot und keine sichere Regel zum Abschotten privater
Verzeichnisse bietet, ist eine reine FTP-Installation nicht sicher
unterstützbar. Das komplette Repository öffentlich abzulegen und einzelne
Dateien nur per Rewrite zu verstecken ist kein gleichwertiger Ersatz.

Mezzio geht ebenfalls von einem separaten öffentlichen Verzeichnis aus; siehe
die [Standalone-Dokumentation](https://docs.mezzio.dev/mezzio/v1/getting-started/standalone/)
und den Hinweis zu Basis-Pfaden bei Unterverzeichnissen in der
[Mezzio-Dokumentation](https://docs.mezzio.dev/mezzio/v3/cookbook/using-a-base-path/).

## Browser-Installer

Der Installer ist unter `httpdocs/install.php` erreichbar und führt durch die
Prüfung von PHP, Pflicht-Erweiterungen, Schreibrechten und dem gewählten PDO-
Treiber. Für eine Installation werden Datenbankzugang, ein erstes
Administratorkonto sowie die erforderlichen System- und Providerdaten benötigt.

Konfigurationen werden über `AtomicConfigurationWriter` atomar geschrieben;
der `FreshInstallBootstrapper` erstellt das Schema und den ersten Benutzer mit
seinem persönlichen Account. Der Installationszustand wird außerhalb des
DocumentRoots in `configs/.installed` gespeichert. Der erste Browserzugriff
initialisiert den Installer-Token für die laufende Session. Nach erfolgreichem
Abschluss ist der Installer gesperrt; ein erneuter Aufruf startet keine
Neuinstallation.

Abgebrochene Läufe bleiben wiederholbar, solange die Installation nicht
abgeschlossen wurde. Datenbankfehler werden protokolliert, aber nicht mit
Zugangsdaten oder rohen Exception-Texten im Browser ausgegeben. Bei einem
Fehler werden Konfiguration und Installationsmarke nicht als erfolgreich
abgeschlossen gespeichert.

Bei älteren Installationen ohne diese Marke werden die drei vollständigen
Bootstrap-Dateien `configs/config.local.toml`, `configs/database.toml` und
`configs/providers.toml` nur dann als kompatibler Abschlusszustand erkannt,
wenn der alte Installer bereits entfernt wurde. Solange `install/` vorhanden
ist, bleibt ein abgebrochener Lauf mit denselben Dateien erneut versuchbar; die
Dateien werden dabei nicht verändert.

## Shared Hosting und PHP-FPM

Auf klassischem Shared Hosting sind PHP 8.4+, der passende PDO-Treiber,
Schreibrechte für die privaten Verzeichnisse und ein auf `httpdocs/` gesetzter
DocumentRoot erforderlich. Composer, Node.js, SSH, Root-Rechte und dauerhafte
Worker werden zur Laufzeit nicht benötigt, müssen aber für einen
Repository-Checkout während der Entwicklung vorhanden sein. Für einen Betrieb
ohne SSH, Composer und Node.js muss ein extern vorbereitetes
Deployment-Verzeichnis bereits `vendor/` und die gebauten Dateien unter
`httpdocs/assets/` enthalten. Der Entwicklungscheckout allein ist dafür nicht
vollständig; die Erstellung eines solchen Verzeichnisses ist nicht Bestandteil
der Anwendung.

Der typische Ablauf auf einem geeigneten Hosting-Paket ist:

1. Ein vorbereitetes TowerDNS-Deployment-Verzeichnis in den privaten Webspace
   übertragen und den DocumentRoot auf `httpdocs/` setzen.
2. Beim Hosting-Provider PHP 8.4+ und den benötigten PDO-Treiber aktivieren und
   eine Datenbank samt Benutzer anlegen.
3. `https://example.org/install.php` aufrufen und die Zugangsdaten eingeben.
4. Nach erfolgreicher Einrichtung mit dem gewählten Admin-Konto anmelden und
   Mail- und Sicherheitseinstellungen prüfen.

Bei PHP-FPM wird `httpdocs/` als Nginx- oder Apache-DocumentRoot konfiguriert;
private Verzeichnisse liegen daneben und gehören dem PHP-FPM-Benutzer. HTTPS,
OPcache, restriktive Dateirechte und eine externe Datenbanksicherung werden
empfohlen. Ein Cronjob ist für den normalen Request-Betrieb nicht notwendig.

## Updates, Schema und Backups

Vor Änderungen Datenbank, `configs/*.toml` (einschließlich des
Verschlüsselungsschlüssels) und bei SQLite die Datei unter `data/` sichern.
Bestehende Installationen dürfen nicht durch einen normalen Request neu
initialisiert werden.

Der normale Anwendungsstart führt keine Schemaänderungen aus. `SchemaManager`
wird beim Fresh-Install und in den entsprechenden Installations-/Testpfaden
explizit aufgerufen. TowerDNS enthält derzeit keine allgemeine, versionierte
Doctrine-Migrationsverwaltung; spätere Schemaänderungen benötigen daher einen
ausdrücklich geplanten Upgrade-Schritt mit Datenbankdump. Eine vorhandene
Installation wird beim normalen Request niemals still mit einem frischen
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

## Prüfgrenzen

Automatisiert werden SQLite-Schema, installer-nahe PHP-Pfade, Anwendungstests,
statische Analysen und die Frontend-Assets geprüft. Ein echter Browserlauf auf
jedem Shared-Hosting-Panel, SMTP-Zustellung sowie Live-Verbindungen zu
MySQL/MariaDB und PostgreSQL sind Umgebungs- bzw. Providerprüfungen und müssen
vor dem jeweiligen Rollout separat bestätigt werden.

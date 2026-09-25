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
beschrieben. Der Installer bietet alle drei Treiber an. Die Migrationstests
verwenden immer eine temporäre SQLite-Datenbank und können zusätzlich gegen
MariaDB 11.4 und PostgreSQL 16 in der optionalen Containerumgebung laufen. Das
HTTP-Integrationstestsystem durchläuft Installation, Login, Dashboard und
Logout separat mit allen drei Datenbanken; die genauen Aufrufe und Grenzen
stehen in [`tests/Integration/README.md`](../tests/Integration/README.md).
MariaDB wird dabei tatsächlich getestet, nicht Oracle MySQL. Diese Container-
Tests ersetzen weder einen Test auf dem konkreten Hosting-Paket noch Tests
gegen echte Provider-APIs.

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
werden dort die schlanken PHP-Seiteneinstiege, der Installer, Favicon und die
gebauten Assets. `src/`, `modules/`, `templates/`, `translations/`, `vendor/`,
`configs/`, `data/`, `cache/`, `logs/`, `tests/` und `install/` müssen außerhalb
des öffentlichen Webroots liegen oder vom Webserver zuverlässig gesperrt sein.

Für Apache liegt eine defensive `.htaccess` in `httpdocs/`. Die direkten
PHP-Seiten benötigen kein `mod_rewrite`; die Kompatibilitäts-Pfadrouten schon.
Falls der Hoster `Options` in `.htaccess` nicht erlaubt, muss `-Indexes` und
`-MultiViews` in der VirtualHost-Konfiguration gesetzt werden. Bei Nginx muss
der Betreiber PHP-FPM für die vorhandenen Seitendateien sowie einen
`try_files`-Fallback auf `index.php` konfigurieren. Wenn ein Hosting-Panel keinen frei
wählbaren DocumentRoot und keine sichere Regel zum Abschotten privater
Verzeichnisse bietet, ist eine reine FTP-Installation nicht sicher
unterstützbar. Das komplette Repository öffentlich abzulegen und einzelne
Dateien nur per Rewrite zu verstecken ist kein gleichwertiger Ersatz.

Mezzio geht ebenfalls von einem separaten öffentlichen Verzeichnis aus; siehe
die [Standalone-Dokumentation](https://docs.mezzio.dev/mezzio/v1/getting-started/standalone/)
und den Hinweis zu Basis-Pfaden bei Unterverzeichnissen in der
[Mezzio-Dokumentation](https://docs.mezzio.dev/mezzio/v3/cookbook/using-a-base-path/).

## PHP-Seiten und Routing

Die Hauptseiten liegen physisch unter `httpdocs/`: `index.php`, `login.php`,
`admin.php`, `users.php`, `roles.php`, `accounts.php`, `zones.php`,
`records.php`, `dnssec.php`, `profile.php` und `settings.php`. Sie laden alle
denselben privaten Bootstrap unter `src/Infrastructure/Http/http-entrypoint.php`.
`install.php` ist der separate Einstieg für die Erstinstallation. Mezzio
übernimmt nach dem gemeinsamen Start weiterhin Session, CSRF, Routen und
Handler; keine dieser Dateien enthält eine zweite Fachimplementierung.

Die bevorzugte Ausgabe verwendet standardmäßig PHP-Adressen. Beispiele:

| Bereich | Adresse |
| --- | --- |
| Dashboard | `/index.php` |
| Rollenliste / Rolle | `/roles.php`, `/roles.php?id=42` |
| Benutzerliste / Benutzer | `/users.php`, `/users.php?id=123` |
| Account / Mitglieder / Provider | `/accounts.php?id=107`, `&view=members`, `&view=providers` |
| Zonen eines Accounts | `/zones.php?account=107` |
| Records / DNSSEC einer Zone | `/records.php?account=107&zone=21`, `/dnssec.php?account=107&zone=21` |

`zones.php?account=107&zone=21` zeigt ebenfalls die Records dieser Zone.
Die Zahlen sind nur Beispiele. IDs, Account-Zugehörigkeit und Rechte werden
weiterhin serverseitig geprüft. Query-Parameter sind keine Autorisierung.
Technische Unteraktionen (etwa WebAuthn, Einladungen und einzelne
Provider-Credential-Operationen) verwenden weiterhin ihre Mezzio-Pfadrouten.
POST und CSRF bleiben für Schreibaktionen erforderlich. Die Hauptseiten
können auch ohne Rewrite-Modul direkt aufgerufen werden; alte Pfadrouten wie
`/roles/{id}` und `/accounts/{account}/zones/{zone}` bleiben kompatibel.

Betreiber können in `configs/config.local.toml` unter `[app]` optional
`url_style = "path"` setzen, damit TowerDNS Pfadrouten als bevorzugte Links
und Redirects ausgibt. Der Standard `php` benötigt keinen zusätzlichen
Konfigurationseintrag. Beide Varianten verwenden dieselben Mezzio-Handler und
Berechtigungsprüfungen. Die `.htaccess` leitet nur nicht existierende Dateien
oder Verzeichnisse an `index.php` weiter; vorhandene PHP-Dateien und Assets
werden direkt ausgeliefert. Sie verhindert Directory-Listings und Apache
MultiViews. Auf Shared Hosting kann eine nicht erlaubte `Options`-Direktive
einen HTTP-500-Fehler verursachen; dann muss der Provider diese beiden
Einstellungen im VirtualHost setzen und die Zeile aus `.htaccess` entfernen.

Für Nginx/PHP-FPM ist beispielsweise folgende Struktur passend (Pfade und
Socket an die eigene Umgebung anpassen):

```nginx
server {
    listen 443 ssl;
    server_name dns.example.org;
    root /srv/towerdns/httpdocs;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php$is_args$args;
    }

    location ~ ^/(index|install|login|admin|users|roles|accounts|zones|records|dnssec|profile|settings)\.php$ {
        try_files $uri =404;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    }

    location ~ \.php$ { return 404; }
    location ~ /\. { deny all; }
}
```

TLS-Zertifikat, HTTPS-Redirect und weitere Hostvorgaben müssen separat
konfiguriert werden. Private Verzeichnisse liegen **oberhalb** des Nginx-Root.
Die `try_files`-Weiterleitung benötigt die ursprüngliche `REQUEST_URI` für
Mezzio. Storybook ist nur eine lokale Vorschau; dort werden bekannte PHP-Links
auf synthetische Stories gelenkt und schreibende Aktionen abgefangen. Es ist
kein Ersatz für den PHP-Router und gehört nicht in `httpdocs/`.

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

TowerDNS stellt derzeit keinen eigenen Backup-/Restore-Assistenten bereit;
Backups und Wiederherstellung erfolgen mit den Werkzeugen des gewählten
Datenbanksystems. Ein Restore ist nur mit der dazugehörigen
`configs/config.local.toml` samt ursprünglichem `security.encryption_key`
vollständig. Nach Wiederherstellung Datenbank und Konfiguration gemeinsam
einspielen und vor einem Schema-Upgrade `towerdns:schema:validate` ausführen.
Bei fehlendem Schlüssel keinen Ersatzschlüssel erzeugen: bereits verschlüsselte
Provider-Zugangsdaten und TOTP-Secrets wären damit nicht mehr entschlüsselbar.
Details stehen unter [Backup, Restore und Key Recovery](ARCHITECTURE.md#backup-restore-und-key-recovery).

Der normale Anwendungsstart führt keine Schemaänderungen aus. Der
`FreshInstallBootstrapper` und der CLI-Installer rufen den versionierten
`SchemaMigrationManager` ausdrücklich auf. Eine vorhandene Installation kann
Migrationen entweder über die CLI oder als authentifizierter Systemadministrator
unter `/settings/schema` ausführen. Der Browserweg ist CSRF-geschützt, erfordert
die Permission `system.schema.manage` und verwendet eine Datenbank-/Dateisperre
gegen parallele Upgrades.

```sh
php bin/towerdns towerdns:schema:status
php bin/towerdns towerdns:schema:validate
php bin/towerdns towerdns:schema:migrate
```

Die Migrationstabelle `towerdns_schema_migrations` zeichnet jede ausgeführte
Migration in fester Reihenfolge auf. Eine aktuelle Bestandsinstallation wird
nur dann als Baseline übernommen, wenn Tabellen, Spalten, Indizes,
Foreign-Keys und Datentypen dem kanonischen Schema entsprechen. Ein
unvollständiger oder inkompatibler Zustand bleibt sichtbar und wird nicht
automatisch als erfolgreich markiert. Additive Strukturmigrationen und
Daten-Backfills sind getrennt; irreversible Änderungen werden nicht als
Rollback versprochen. Nach einem Fehler kann der kontrollierte Befehl erneut
ausgeführt werden, sofern die konkrete Datenbank-DDL keinen manuellen Restore
erfordert.

Doctrine DBAL und Doctrine Migrations abstrahieren die unterstützten PDO-
Treiber. Der vorhandene Migrations-Integrationstest läuft immer mit SQLite und
kann zusätzlich für MariaDB und PostgreSQL konfiguriert werden. Die optionale
Compose-Konfiguration pinnt dafür MariaDB 11.4 und PostgreSQL 16. Der
vorhandene HTTP-E2E-Test durchläuft den kompletten Webinstaller-/Login-/
Dashboard-/Logout-Ablauf mit SQLite, MariaDB und PostgreSQL. Dabei sendet der
Test echte HTTP-Anfragen an den Apache/PHP-Testcontainer; er ist aber kein
grafischer Browser- oder Hostinganbieter-Test. Die genauen Aufrufe stehen in
[`tests/Integration/README.md`](../tests/Integration/README.md).
Die Testdatenbanken und Container sind keine Laufzeitvoraussetzung für TowerDNS
und ersetzen keine Validierung mit der tatsächlichen Hosting-Datenbank.

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

Der automatisierte Testbestand umfasst Anwendungstests, statische Analysen,
Frontend-Assets, Migrationen mit SQLite sowie optional MariaDB 11.4 und
PostgreSQL 16 und den vollständigen HTTP-Installations-/Login-/Dashboard-/
Logout-Ablauf mit allen drei Datenbanken. Das HTTP-E2E verwendet `curl` gegen
den isolierten Apache/PHP-Container, keine grafische Browserautomation. Nicht
abgedeckt sind reale Shared-Hosting-Panels und deren Dateirechte/PHP-FPM-
Konfiguration, SMTP-Zustellung, produktive Datenbanken oder Live-Provider-
Anfragen. Oracle MySQL ist kein eigenständiges Integrationsziel.

[`DatabaseConfigurationRestoreIntegrationTest`](../tests/Integration/DatabaseConfigurationRestoreIntegrationTest.php)
ergänzt eine isolierte dateibasierte SQLite-Prüfung: Sie sichert eine
geschlossene Testdatenbank zusammen mit der Konfiguration, führt eine bekannte
Schema-Aktualisierung aus und stellt anschließend beide Dateien wieder her.
Geprüft werden danach
Passwort-Hash, Accounts, Owner-Memberships, Limits, Zone und die Entschlüsselung
eines Test-Credentials mit dem wiederhergestellten Schlüssel. Das ist kein
Test eines Live-Backups bei gleichzeitig laufender Anwendung; native Backup-
und Restore-Verfahren für MariaDB/PostgreSQL sowie ein HTTP-Login nach Restore
sind damit ebenfalls nicht validiert.

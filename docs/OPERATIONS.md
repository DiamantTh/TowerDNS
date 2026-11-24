# TowerDNS Betrieb: Upgrade und Wiederherstellung

## Vor einem Upgrade

Ein Upgrade benötigt eine vorbereitete TowerDNS-Distribution mit `vendor/`,
aktivierten Frontend-Assets, Manifesten und Installationsdateien. Composer und
Node.js sind auf dem Zielserver nicht erforderlich, wenn ein geprüftes
Distributionspaket verwendet wird. Vor dem Umschalten auf den neuen Code:

1. Paket lokal bauen und dessen Frontend-/Paketprüfungen ausführen.
2. Wartungsfenster planen und die TowerDNS-Schreibzugriffe anhalten.
3. Datenbank **und** passende Konfiguration sichern und die Sicherung durch
   eine Wiederherstellung in einer isolierten Testumgebung prüfen.
4. `php bin/towerdns towerdns:upgrade:check` ausführen. Der Befehl ist
   ausschließlich lesend. Er prüft PHP/Erweiterungen, DB-Verbindung, lokale
   Konfiguration und Anwendungsschlüssel ohne deren Werte auszugeben,
   Runtime-Schreibpfade, Module, Frontend-Release sowie Schema- und
   Migrationsstatus. Ausstehende Migrationen sind erwartbar, werden aber
   ausdrücklich ausgewiesen.
5. Den neuen Code als vollständige vorbereitete Distribution bereitstellen und
   anschließend `php bin/towerdns towerdns:schema:status` prüfen.
6. Migrationen nach verifiziertem Backup bewusst ausführen:
   `php bin/towerdns towerdns:schema:migrate --confirm`.
7. `php bin/towerdns towerdns:schema:validate`, `/ready` und die wichtigsten
   Login-, Zonen-, Provider- und TLSA-Leseabläufe prüfen.

`towerdns:upgrade:check` ersetzt weder Backup noch Migration. Es führt keine
DDL-Änderungen aus und kann nicht beweisen, dass dem Datenbankbenutzer alle
später benötigten DDL-Rechte erteilt wurden. Bei einem fehlgeschlagenen
Upgrade kann ein Datenbanksystem bereits DDL ausgeführt haben: die Meldung
bedeutet ausdrücklich **nicht**, dass der Vorgang zurückgerollt wurde. Danach
Schema-Status und Tabellen prüfen; bei Unklarheit die verifizierte Sicherung
wiederherstellen oder die Migration gezielt reparieren, bevor erneut migriert
wird.

### Release-Checkliste

- Passende Distribution und CLI-Hilfe prüfen. Die PHP- und Frontend-Prüfungen
  ausführen und die Assets aktivieren.
- Vor der Migration TowerDNS-Schreibzugriffe und Scheduler anhalten. Datenbank,
  Konfiguration und Schlüssel sichern. Verify und einen isolierten Restore
  durchführen. Ohne prüfbare Rückkehrmöglichkeit abbrechen.
- `towerdns:upgrade:check --format=json` und `towerdns:schema:status` auswerten.
  Erst danach die Migration ausdrücklich bestätigen.
- Schema, `/ready`, Login, Rollen, Zone, TLSA-Lesezugriff und den Monitoringstatus
  kontrollieren. Die Outbox prüfen, bevor der Benachrichtigungsscheduler wieder
  aktiviert wird.
- Bei fehlgeschlagenem Smoke-Test die Instanz offline halten. Entscheiden, ob die
  Migration kontrolliert fortgesetzt oder der passende gesicherte Stand in
  einer isolierten Datenbank wiederhergestellt wird.

## Backup und Restore

Die Backup-CLI sichert die TowerDNS-Datenbank einschließlich Benutzerkonten,
WebAuthn-Binärwerten, TOTP, RBAC, API-Key-Prüfwerten und Gültigkeit, Provider-
und Zonenzuordnungen, Audit sowie Monitoringregeln, Historie und Zustellstatus.

Separat sichern:

- `configs/config.local.toml` mit dem ursprünglichen Anwendungsschlüssel, der
  WebAuthn-RP-ID, dem Origin und der Mailkonfiguration.
- `configs/database.toml`, `configs/providers.toml`, weitere Betreiber- und
  Moduldaten sowie Deployment-Secrets, etwa `DB_PASSWORD` aus dem Secret Store.
  Externe private Schlüssel und Zertifikate nach dem Verfahren ihres Betreibers
  sichern.
- Die passende vorbereitete Distribution mit Modulen, Themes, Abhängigkeiten
  und geprüften Assets. Site-spezifische Uploads, zusätzliche Runtime-Dateien
  und Webserverkonfiguration separat inventarisieren. Sitzungen und
  Rate-Limit-Caches für einen sauberen Wiederanlauf neu beginnen.

Datenbank und Konfigurationsstand gemeinsam dokumentieren. Während der Sicherung
keine Konfigurations- oder Schlüsseländerungen durchführen. Der Schlüssel
entschlüsselt die gespeicherten Providerzugänge und TOTP-Secrets. Das Manifest
enthält seinen SHA-256-Prüfwert zur Zuordnung, der Schlüssel selbst bleibt im
Secret Store. Bei Schlüsselrotation alte Schlüssel für die zugehörigen
Sicherungen geschützt aufbewahren und neue Sicherungen erstellen.

### Backup erstellen und prüfen

```sh
install -d -m 0700 /srv/private/towerdns-backups
php bin/towerdns towerdns:backup:create /srv/private/towerdns-backups/snapshot-001 --allow-plaintext
php bin/towerdns towerdns:backup:verify /srv/private/towerdns-backups/snapshot-001
```

Die Beispiele setzen ein privates, betreibergeschütztes Verzeichnis außerhalb
jedes Webroots und Git-Checkouts voraus. Der Zielordner muss neu sein.
`database.dump` und `manifest.json` haben Modus 0600, der Ordner 0700. Das
Manifest wird erst nach der Sicherung und Formatprüfung veröffentlicht. Wenn
ein Prozess hart abgebrochen wird, kann ein privater Ordner ohne vollständiges
Manifest zurückbleiben. Erst nach Prüfung durch den Betreiber bereinigen.

**Das Bundle ist unverschlüsselt.** `--allow-plaintext` bestätigt die bewusste
Entscheidung. Für Aufbewahrung und Transport ein verschlüsseltes Volume oder
ein externes Werkzeug mit authentifizierter Verschlüsselung verwenden. Den
Entschlüsselungsschlüssel getrennt sichern und Wiederherstellung regelmäßig
üben. Prüfsummen erkennen Beschädigungen, authentifizieren den Herausgeber
aber nicht. Ausschließlich Sicherungen aus vertrauenswürdiger Herkunft
verwenden, insbesondere bei nativen SQL-Archiven.

| Datenbank | Verfahren und Voraussetzungen |
| --- | --- |
| SQLite | `VACUUM INTO` über den vorhandenen PDO-Treiber. Konsistenter Snapshot auch in WAL. Integritäts- und Fremdschlüsselprüfung beim Verifizieren. |
| MariaDB | `mariadb-dump` mit konsistentem Transaktionssnapshot für InnoDB und binärtreuem Export. Dedizierte TowerDNS-Datenbank. Zusätzliche Serverobjekte wie Trigger, Routinen und Events liegen außerhalb des Backupvertrags. Restore über `mariadb`. |
| PostgreSQL | `pg_dump` im Custom-Format, Schema `public`. `pg_restore` prüft das Archive-Verzeichnis und stellt in einer Transaktion wieder her. Serverrollen und Grants separat verwalten. |

Native Programme müssen im CLI-PATH liegen. Für PostgreSQL müssen Dump- und
Restore-Client dieselbe Hauptversion wie ihr jeweiliger Server haben. Neuere
Restore-Clients können Sitzungsparameter senden, die ältere Server ablehnen.
Verbindungsdaten übernimmt die CLI aus der vorhandenen DBAL-Konfiguration.
Passwörter gelangen über kurzlebige Dateien im privaten Arbeitsverzeichnis
zum Client. Keine Zugangsdaten in Prozessargumenten. TowerDNS sperrt seine
eigenen Migrationen während der Sicherung. Externe DDL, Plugins und
Schemaverwaltungsprozesse müssen im Wartungsfenster angehalten werden.

Die Befehle geben bei Erfolg JSON aus. Exitcode 0 bedeutet Erfolg, 1 einen
fehlgeschlagenen Vorgang und 2 eine fehlende oder ungültige CLI-Freigabe.
`verify` prüft die Integrität und das Format. Für native Server-Dumps ist die
vollständige Probe der tatsächliche Restore in eine leere Testdatenbank.

### Offline wiederherstellen

```sh
install -d -m 0700 /srv/private/towerdns-restore
php bin/towerdns towerdns:backup:restore /srv/private/towerdns-backups/snapshot-001 \
  --sqlite-target=/srv/private/towerdns-restore/recovered.sqlite --confirm=offline-restore
```

SQLite benötigt ein neues Dateiziel ohne Journal- oder WAL-Dateien. Der
geprüfte Snapshot wird aus einer privaten Staging-Datei veröffentlicht, ohne
eine vorhandene Datei zu ersetzen. Für Serverdatenbanken eine leere,
isolierte Datenbank mit Namen `towerdns_restore_<name>` bereitstellen und den
DBAL-Zugang in einem separaten Checkout darauf setzen. Dann anstelle von
`--sqlite-target` die Option `--server-target` verwenden. Keine anderen
Verbindungen oder Scheduler auf diesem Ziel zulassen. Ein Fehler beim
MariaDB-Import kann Teilergebnisse hinterlassen. Vor einem erneuten Versuch
die isolierte Datenbank prüfen oder neu bereitstellen.

Den ursprünglichen Anwendungsschlüssel in der lokalen Konfiguration
bereitstellen. Bei einem älteren unterstützten Migrationsstand das
Upgrade-Preflight und die explizite Migration anschließend ausführen. Vor der
Freigabe Konten, Passwort-Anmeldung, WebAuthn und TOTP, Rollen, API-Scopes und
Zonen kontrollieren. Seit dem Backup erfolgte Sperren, Passwortwechsel und
Widerrufe anhand einer getrennten aktuellen Quelle nachvollziehen und erneut
anwenden. Alte Sitzungsspeicher nicht übernehmen. Die Benachrichtigungs-CLI
erst nach Prüfung der wiederhergestellten Warteschlange starten.

Der Datenbank-Restore ändert keine externen DNS-Daten. Providerzugang,
Zonenzuordnung und TLSA-RRsets nach der Wiederherstellung mit den vorhandenen
lesenden Provider- und DNS-Abfragen kontrollieren. Ein Code-Rollback setzt
voraus, dass die frühere Version das vorliegende Schema verträgt. Nach
fehlgeschlagenem DDL ist die Wiederherstellung eines passenden
Code-/Konfigurations-/Datenbankstands der kontrollierte Weg.

Für Cron oder systemd-Timer feste private Pfade, einen dedizierten
Betriebsbenutzer und pro Lauf einen neuen Ordner vorsehen. Auf freien Platz
für Dump und Restore-Staging achten. Überlappende Läufe per Scheduler
ausschließen, Exitcodes im externen Backupmonitoring auswerten und Rotation
erst nach erfolgreichem Verify durchführen. Die 11A-Mailwarteschlange nimmt
keine Backup-Fehler entgegen.

## Lesendes DNS-Monitoring

Das Monitoring ist pro verwalteter Zone ausdrücklich zu aktivieren. Es führt
keine DNS-Änderungen aus. Ein Administrator kann es in `/admin/monitoring` oder
über die CLI konfigurieren:

```sh
php bin/towerdns towerdns:monitor:configure 123 --enable --smtp-dane --interval=60
php bin/towerdns towerdns:monitor:run --format=json
php bin/towerdns towerdns:monitor:run --zone=123 --force --format=json
```

Der Betreiber richtet den Scheduler (zum Beispiel systemd-Timer oder Cron)
selbst ein. Ohne Scheduler erfolgen keine periodischen Prüfungen. Intervalle
sind auf 5 bis 1440 Minuten beschränkt; ein Sammellauf prüft höchstens 250
fällige Zonen. Noch nie geprüfte und am längsten nicht geprüfte Zonen werden
zuerst berücksichtigt. Eine gezielte `--zone`-Prüfung wird direkt abgefragt und
ist nicht durch die Sammelgrenze abgeschnitten.

Aktuell wird die vorhandene SMTP-DANE-DNS-Diagnose über den konfigurierten
validierenden Resolver wiederverwendet. Das Ergebnis beschreibt DNSSEC/MX/TLSA,
nicht einen SMTP-Handshake oder eine DANE-Authentifizierung. Resolverfehler
werden als fehlgeschlagene Ausführung und nicht als bestätigter DNSSEC-Bogus-
Zustand gespeichert. Die Datenbank hält den jüngsten Zustand je Zone/Prüftyp
sowie kompakte Ereignisse bei Zustandsänderungen. Ereignisse werden nach 180
Tagen entfernt; unveränderte Wiederholungen aktualisieren nur den Prüfzeitpunkt.
Status und begrenzte Historie sind Systemadministratoren in
`/admin/monitoring` zugänglich.

Unter `/admin/monitoring` lassen sich pro Zone ein Empfänger, Sprache,
Mindestschweregrad, Bestätigung (2–5 Prüfungen), Quittierung und Wartungszeit
(bis 168 Stunden) einstellen. Für einen relativen TLSA-Dienstnamen lassen sich
bis zu 16 erwartete Werte hinterlegen. Direkte autoritative Abfragen vergleichen
die erwarteten Werte pro antwortendem Nameserver. Zusätzliche Werte sind erlaubt,
etwa beim Rollover. Diese Abfragen validieren kein DNSSEC. Ein bestätigter
DNSSEC-Bogus-Zustand bleibt bei vorübergehend unbekanntem Ergebnis aktiv.

Die API liefert über
`GET /api/v1/accounts/{account}/zones/{zone}/monitoring?days=30`
den letzten Zustand, aktive Warnung, Ereignisse und bis zu 20 Zustellstatus.
Für die nächste Seite `before` auf `next_before` setzen und `days` beibehalten
(1–180 Tage, 50 Ereignisse pro Seite). Zeiten ohne Offset sind UTC. Der
Bearer-Scope `monitoring:read` gilt für eine Zone ohne Owner und erfordert
weiterhin `record.read`. Die Zonenansicht prüft dieselbe Leseberechtigung.
Zonenbenutzer mit `zone.update` können dort Aktivierung und Intervall ändern.
Empfänger, Wartungszeit und Quittierung benötigen `system.settings.manage`.

Für E-Mail in der betreibergeschützten `config.local.toml`:

```toml
[monitoring_mailer]
enabled = true
host = "smtp.example.org"
port = 587
implicit_tls = false
from_address = "towerdns@example.org"
# username und password optional aus geschützter Konfiguration
```

Nur einen vom Betreiber kontrollierten SMTP-Endpunkt konfigurieren. SMTP
verwendet TLS mit Zertifikatsprüfung und prüft keine URLs aus Webanfragen.
Im Scheduler nach dem Prüflauf `php bin/towerdns towerdns:monitor:notify`
aufrufen. Der Befehl beansprucht bis zu 20 Zustellungen atomar. Fehler beim
Verbindungsaufbau erhalten bis zu drei Versuche mit Wartezeit. Ein Fehler nach
Beginn der Übermittlung oder ein unterbrochener Versand wird als unklar
gespeichert. Bestätigte Ereignisse und Zustellungen werden jeweils nach 180
Tagen durch die zugehörigen CLI-Läufe bereinigt. Bei aktivem Versand werden
für gültige zonengebundene API-Schlüssel 14 Tage vor Ablauf Hinweise eingeplant.
TowerDNS prüft die aktuellen Versandbedingungen nach dem Reservieren und erneut
nach dem SMTP-Verbindungsaufbau. Bereits wartende Meldungen werden verworfen, wenn die Zone inzwischen
deaktiviert oder stummgeschaltet, der Empfänger geändert oder der Schlüssel
widerrufen wurde. Eine SMTP-Annahme bestätigt die Übergabe an den Mailserver.

Die Migration ist additiv. Backup und Wiederherstellung umfassen auch
Monitoring-Einstellungen, Historie und Zustellstatus. Nach der Wiederherstellung
sollte der Betreiber wartende Zustellungen prüfen, bevor er den Versand
wieder startet.

## Betriebsgrenzen

Provider-Readbacks und TLSA-/DNSSEC-Diagnosen bleiben zeitgebundene
Beobachtungen, keine automatische Reparatur. DNS-Propagation,
Providerverfügbarkeit und öffentliche Resolver können vom gespeicherten
Zustand abweichen. Lokale oder Mock-Tests belegen keine Live-Provider-,
öffentliche DNSSEC- oder DANE-Prüfung. Diese Dokumentation behauptet weder eine
Produktivinstallation noch Live-Validierung.

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

## Backup und Restore

Ein vollständiges Anwendungsbackup besteht aus:

- konsistenter Datenbanksicherung (Benutzer, WebAuthn-/TOTP-Credentials,
  Rollen, API-Key-Digests, Providerzuordnungen, Audit- und TLSA-Daten);
- `configs/` einschließlich der zur Datenbank passenden
  `security.encryption_key` und Providerkonfiguration;
- gegebenenfalls zusätzlich benötigter Deployment-/Webserverkonfiguration.

Der Anwendungsschlüssel ist notwendig, um verschlüsselte Providerzugänge und
TOTP-Secrets wieder zu entschlüsseln. Er muss separat und mindestens so gut
geschützt wie das Datenbankbackup gesichert werden. Keine Schlüssel oder
Passwörter in Kommandozeilenargumente, Git, Supporttickets oder ungeschützte
Backupverzeichnisse schreiben. DB-native Werkzeuge und geschützte
Credential-Dateien beziehungsweise Secret-Management verwenden. Bei SQLite
eine konsistente Online-Sicherung über SQLite-Backupfunktionen oder die
Anwendung nach sauberem Stop sichern; nicht blind eine aktive WAL-Datenbank
kopieren.

Restore-Reihenfolge: TowerDNS stoppen, Codeversion und Konfiguration passend
zum Backup bereitstellen, Datenbank in eine isolierte beziehungsweise
freigegebene Ziel-DB wiederherstellen, Runtime-Dateirechte prüfen und erst
dann Anwendung starten. Anschließend Schema-Status, Schema-Validierung,
Anmeldung und Provider-Readback prüfen. Ein Restore der TowerDNS-Datenbank
spielt **keine** früheren DNS-Records beim externen Provider zurück. Provider-
Zustände separat lesend vergleichen; keine automatische vollständige
Zonensynchronisierung oder RRset-Ersetzung als Restore ausführen.

Ein Code-Rollback ist nur sicher, wenn die alte Anwendung das bereits
migrierte Schema unterstützt. TowerDNS-Migrationen können irreversibel sein;
Downgrade-Migrationen dürfen nicht angenommen werden. Bei einem Upgradefehler
ist oft der sichere Rückweg: Anwendung anhalten, zueinander passende
Code-/Konfigurations-/Datenbanksicherung wiederherstellen und danach Zustand
validieren.

## Betriebsgrenzen

Die bestehenden Provider-Readbacks und TLSA-/DNSSEC-Diagnosen liefern
Beobachtungen zum Zeitpunkt der Prüfung, keine automatische Reparatur. DNS-
Propagation, Providerverfügbarkeit und öffentliche Resolver können vom
gespeicherten lokalen Zustand abweichen. Monitoring/Benachrichtigungen sind in
diesem Checkout nur dann verfügbar, wenn der jeweilige CLI-/Service-/UI-Code
tatsächlich installiert ist; ein Auftragstext allein ist kein
Implementierungsnachweis. Diese Dokumentation behauptet weder eine
Produktivinstallation noch Live-Provider- oder DANE-Validierung.

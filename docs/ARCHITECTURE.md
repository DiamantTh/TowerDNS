# TowerDNS Architektur

## Ziel

TowerDNS ist ein eigenständiges DNS-Management-Panel mit gemeinsamer Kernlogik und austauschbaren, capability-orientierten Provider-Adaptern. Das frühere desec-manager-Projekt war unvollständig und ist weder Architektur- noch Funktionsreferenz; konkrete Codeherkunft ist unter [Projektgeschichte](PROJECT_HISTORY.md) dokumentiert.

## Layer-Modell

```
src/
  Domain/         kanonische DNS-, RBAC- und Account-Modelle
  Application/    Workflows, Validierung, Capabilities, Rechtepruefung, Services
  Infrastructure/ HTTP-Handler, Persistenz, Console, Provider-Komposition
modules/            isolierte Provider-Module und deren API-Adapter
```

1. **Infrastructure/Http** enthaelt die PSR-15-Handler (Mezzio). Handler delegieren ausschliesslich an Application-Services und kennen keine Provider-spezifischen Endpunkte.
2. **Application** orchestriert DNS-Abläufe über `ManagedZoneDNSService`, normalisiert Eingaben (`DNSNameValidator`, `RecordValidator`), prüft Rechte und Account-Scope und erzwingt die deklarierten Provider-Capabilities.
3. **Domain** stellt DNS- und RRset-Wertmodelle (`Zone`, `Record`, `DNSRecordType`, `Rrset`, `DNSSECProfile`, `DNSSECState`), RBAC-Modelle und Account-gebundene Ressourcen bereit.
4. **modules/** kapselt externe Provider-APIs. Module deklarieren Zugangsdaten und bilden provider-spezifische Unterschiede ab; `ProviderModuleRegistry` und Factorys registrieren bzw. instanziieren sie.
5. **Infrastructure/Persistence** implementiert Repository-Interfaces per Doctrine DBAL 4. `SchemaManager` bleibt Grundlage für den Legacy-/Fresh-Install-Übergang; versionierte Doctrine-Migrationen sind der kontrollierte Updatepfad.

## Provider-Registry

Provider werden über Module und die `ProviderRegistry` bzw. accountbezogene Provider-Factorys bereitgestellt. `ProviderAccount` speichert eine verschlüsselte, account-eigene Verbindung; systemweite Provider-Konfiguration bleibt davon getrennt. Workflows wählen Adapter über stabile Provider-IDs, nicht über direkt injizierte Einzelanbieter.

## Capability-orientiertes Modell

Provider werden nicht auf einen kleinsten gemeinsamen Nenner reduziert. Jeder Adapter deklariert in seiner `capabilityMap()`, welche `Capability::*`-Konstanten er liefert. DNS-Workflows lehnen Operationen ab, für die der ausgewählte Provider keine passende Capability anbietet. Die aktuelle Matrix steht in [PROVIDER_CAPABILITIES.md](PROVIDER_CAPABILITIES.md).

Beispiele: Zonen- und Record-CRUD, Record-Kommentare, DNSSEC-Status und -Aktionen, Schlüsselzugriff sowie Zugangsdatenverwaltung. Adapter werden nicht als vollständig austauschbar oder gleich funktionsreich vorausgesetzt.

## DNSSEC

DNSSEC-Status ist Teil des Domain-Modells. Sichtbare manuelle Aktionen werden aus der tatsächlichen Capability abgeleitet; der konkrete Status- und Aktionsumfang ist in der [Provider-Matrix](PROVIDER_CAPABILITIES.md#dnssec) dokumentiert.

## Authentifizierung und Rechtepruefung

Die Webidentität wird aus der Mezzio-Session durch `TowerDNSSessionAuthentication` ermittelt. Der Adapter implementiert `Mezzio\Authentication\AuthenticationInterface`, delegiert Session-Laufzeit/MFA-Pending an `SessionSecurity` und lädt den aktiven Benutzer einschließlich seiner aktuellen Rollen aus dem Repository. `AuthenticationMiddleware` stellt diesen kanonischen Domain-User zusätzlich als `Mezzio\Authentication\UserInterface` bereit. Im Admin-Switch werden beide Identitätsdarstellungen auf den effektiven Zielbenutzer ausgerichtet; `RequireAuthMiddleware` weist inkonsistente oder fehlende Identitäten zurück.

`AuthorizationService` prüft globale System-Permissions über das vorhandene Laminas-RBAC-Verhalten. `PermissionService` ergänzt Account- und ManagedZone-Scope aus aktiven Accounts und tatsächlichen Memberships. Eine systemweite Account-Sichtbarkeit (`SYSTEM_ACCOUNTS_ACCESS`) gewährt nur ausdrücklich erlaubte Leseoperationen; sie ist kein Schreib-Bypass. `ActiveAccount` ist lediglich Kontext. Die DNS-Application-Services prüfen darüber hinaus Account-/Zonen-Zuordnung und die Capabilities des gewählten ProviderAdapters.

IAM-Mutationen laufen über Application-Services: `IamAdministrationService` lädt Actor und Ziele erneut aus der Datenbank, globale Rollenzuweisungen verlangen `USER_MANAGE` plus `ROLE_MANAGE` und delegierbare Rechte; Built-in-Superadmin-Verwaltung ist auf den echten Built-in-Operator begrenzt. Status-/Rollen-/Löschoperationen schützen den letzten aktiven Superadmin und werden transaktional serialisiert sowie auditiert. Hochsensible Rollen-, Benutzerstatus-, Lösch- und administrative Passwortänderungen verlangen zusätzlich einen frischen, zielgebundenen TOTP-/Passkey-Step-up. Der Beginn eines Admin-Switches nutzt denselben Proof-Mechanismus. Eine kurzlebige Nonce-Tabelle verhindert parallele Wiederverwendung eines Proofs; sie wird kontrolliert durch Doctrine-Migrationen eingerichtet und nie im normalen HTTP-Bootstrap erzeugt. Weitere Details und Grenzen stehen in [RBAC.md](RBAC.md).

## Benutzer, Profil und Accounts

`User` ist die Login-Identitaet; Ressourcen gehoeren ausschliesslich einem `Account`. `AccountMembership` verbindet beide. `UserLifecycleService` legt bei der Benutzererstellung transaktional einen persoenlichen Account mit Owner-Membership und ResourceLimits an. Der Fresh Install erzeugt fuer den ersten Administrator zusaetzlich zum benannten Organisations-Account einen persoenlichen Account. Bestandsbenutzer ohne diesen Container erhalten ihn bei der naechsten authentifizierten Anfrage idempotent. Bei Benutzerloeschung verhindern weitere Mitglieder im persoenlichen Account, eigene Ressourcen oder noch gehaltene Organisations-Accounts eine stille Loeschung.

`accounts.account_type` unterscheidet explizit `personal` und `organization`; `personal_user_id` ist fuer persoenliche Accounts eindeutig und dauerhaft an den Owner gebunden. Der technische Slug `personal-<user-id>` bleibt reserviert, ist aber keine Berechtigungs- oder Typquelle. Der additive Schema-Uebergang erkennt fruehere persoenliche Slugs einmalig und legt fehlende persoenliche Accounts mitsamt Owner-Membership und Limits an. `ActiveAccount` bleibt nur Scope und verleiht keine Rechte.

`users.language` steuert den Translator, `users.locale` die regionale Darstellung und `users.timezone` die Zeitdarstellung; sichere Defaults sind jeweils `en-GB`, `en-GB` und `UTC`. Optionale Kontaktfelder und `external_reference` gehoeren zur Person, waehrend `accounts.customer_number` und `accounts.external_reference` spaetere ERP-/CRM-Anbindungen fuer Organisationen vorbereiten. Passwort-Hash, TOTP-Secrets und andere Zugangsdaten sind nicht Teil des Profilmodells. Profil- und Zugangsdaten-Routen sind waehrend Impersonation gesperrt.

## Erweiterung

Neuen Provider hinzufuegen:

1. Ein Provider-Modul in `modules/<Provider>/` ergänzen, das `ProviderModuleInterface` implementiert.
2. Zugangsdaten und Benutzer-/Systemkonfiguration in `ProviderDefinition` beschreiben.
3. Adapter auf `DNSProviderInterface` aufbauen und nur tatsächlich vorhandene Funktionen in `capabilityMap()` deklarieren.
4. Provider-API-Aufrufe mit isolierten simulierten Antworten testen und die Matrix aktualisieren.

## Sicherheits-Subsystem

### Authentifizierung

- **Passwort** aktuell mit Argon2id (`password_hash`), Policy-gesteuert via `PasswordPolicy` (Mindestlaenge, zxcvbn-Score). Vorhandene Legacy-Hashes werden beim erfolgreichen Login geprüft und auf Argon2id re-gehasht.
- **TOTP** (`TotpService`): SHA-512, 8 Stellen, 64-Byte-Secret, Aegis/FreeOTP+-kompatibel. Google Authenticator ist wegen SHA-1-Beschraenkung inkompatibel.
- **FIDO2/WebAuthn** (`WebAuthnService`): Resident-Key-Support, ES256/RS256, challenge-basierte Login-Ceremony. Persistierte Credentials werden über die aktuelle öffentliche `CredentialRecord`-API des WebAuthn-Pakets serialisiert; das vorhandene JSON-Format früherer `PublicKeyCredentialSource`-Objekte bleibt lesbar, weil es keine PHP-Klassenbezeichnung speichert.

### Kontrollierte Migrationen und Upstream-Kopplung

`SchemaMigrationManager` kapselt die programmgesteuerte Ausführung an einer
einzigen Infrastrukturgrenze. Doctrine Migrations 3.9 kennzeichnet
`Migrator` und `MigratorConfiguration` als `@internal`, bietet für denselben
programmgesteuerten Anwendungsfall jedoch keine öffentliche Runner-API an;
die öffentliche Console ist keine gleichwertige Einbettung für TowerDNS.
Diese kleine, dokumentierte Kopplung bleibt deshalb bewusst isoliert und ist
bei jedem Doctrine-Migrations-Update zusammen mit den Migrationsintegrationstests
zu prüfen. Sie ist nicht als deprecated API klassifiziert.

### HIBP-Integration (Have I Been Pwned)

`HibpRangePasswordChecker` implementiert `BreachedPasswordCheckerInterface` via k-Anonymity Range-API (kein Klartextpasswort verlaesst das System). PSR-18 als Client-Interface; Guzzle wird nur in Kompositionsstellen (`ContainerFactory`, Console-Commands) als Konkrete eingesetzt. Im Air-gapped-Betrieb oder wenn HIBP in `system_settings` deaktiviert ist, wird automatisch `NullBreachedPasswordChecker` gewaehlt.

### Konfiguration: Bootstrap vs. Runtime

| Quelle           | Enthaelt                                               |
|------------------|--------------------------------------------------------|
| TOML-Config      | DB-Verbindung, `encryption_key`, Hostname, App-Name    |
| `system_settings`| Passwort-Policy, HIBP-Flags, zukuenftige Runtime-Werte |

TOML enthaelt Bootstrap- und Betriebsparameter wie DB-Verbindung, Verschluesselungsschluessel, Hostname, App-Name, den internen Mailer-Transport und das globale Theme. Die Systemeinstellungen zeigen eine kleine, bewusst freigegebene Teilmenge davon als SMTP-Host, Port, TLS/STARTTLS, Benutzername, Passwort und Absender. Das SMTP-Passwort und die interne Symfony-Mailer-DSN werden nie an den Browser ausgegeben; ein leeres Passwortfeld behaelt den vorhandenen Wert. Laufzeitwerte der Passwort-Policy und HIBP-Integration liegen in `system_settings` (`DbalSystemSettingsRepository`, per-Request-Cache).

### Backup, Restore und Key Recovery

Ein Restore ist nur vollstaendig, wenn **beide** Bestandteile gemeinsam gesichert und wiederhergestellt werden:

1. **Datenbank** (SQLite-Datei bzw. `pg_dump`/`mysqldump`-Export je nach Treiber).
2. **`configs/config.local.toml`** (und, falls vorhanden, `configs/database.toml`/`configs/providers.toml`), insbesondere `security.encryption_key`.

Provider-Credentials und TOTP-Secrets werden mit `security.encryption_key` reversibel verschluesselt in der DB abgelegt (`CredentialService`). Ein DB-Dump allein ist damit **nicht** wiederherstellbar nutzbar: ohne den passenden Schluessel bleiben alle verschluesselten Werte dauerhaft unlesbar. Beide Artefakte muessen daher als zusammengehoerige Einheit gesichert, transportiert und aufbewahrt werden (z.B. gemeinsam verschluesseltes Backup-Archiv, getrennt von der Produktionsumgebung).

`ContainerFactory` und `InstallCommand` erzeugen `encryption_key` **ausschliesslich einmalig** beim Fresh Install. Es gibt bewusst keinen Laufzeit-Fallback, der bei fehlendem oder ungueltigem Schluessel automatisch einen neuen erzeugt (`ContainerFactory::create()` wirft stattdessen eine `RuntimeException`) — ein automatisch neu erzeugter Schluessel wuerde alle bestehenden verschluesselten Daten unbemerkt und permanent unlesbar machen. Geht der Produktions-Schluessel verloren und existiert kein Backup davon, sind Provider-Credentials und TOTP-Secrets nicht wiederherstellbar; betroffene ProviderAccounts und TOTP-Registrierungen muessen dann manuell neu angelegt werden.

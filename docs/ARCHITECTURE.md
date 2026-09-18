# TowerDNS Architektur

## Ziel

TowerDNS ist ein DNS-Management-Panel mit gemeinsamer Kernlogik und austauschbaren, capability-orientierten Provider-Adaptern. Es ist der offizielle technische Nachfolger von [desec-manager](https://github.com/DiamantTh/desec-manager); deSEC ist nur noch ein Provider unter mehreren.

## Layer-Modell

```
src/
  Domain/         kanonische DNS-, RBAC- und Account-Modelle
  Application/    Workflows, Validierung, Capabilities, Rechtepruefung, Services
  Infrastructure/ Provider-Adapter, HTTP-Handler, Persistenz, Console
```

1. **Infrastructure/Http** enthaelt die PSR-15-Handler (Mezzio). Handler delegieren ausschliesslich an Application-Services und kennen keine Provider-spezifischen Endpunkte.
2. **Application** orchestriert Workflows, normalisiert Eingaben (`DnsNameValidator`, `RecordValidator`), prueft Rechte zentral (`AuthorizationService`) und prueft Provider-Faehigkeiten (`Capability` + `ProviderCapabilitySet`).
3. **Domain** stellt das kanonische DNS-Modell (`Zone`, `Record`, `RecordType`, `DnssecProfile`, `DnssecState`), das RBAC-Modell (`User`, `Role`, `Permission`) und das Account-Modell (`Account`, `AccountMembership`, `ZoneMembership`, `AuditLogEntry`).
4. **Infrastructure/Provider** kapselt jede externe API in einem eigenen Adapter unter `src/Infrastructure/Provider/<Provider>/`.
5. **Infrastructure/Persistence** implementiert alle Repository-Interfaces per Doctrine DBAL. `SchemaManager` verwaltet das DB-Schema und die Seed-Daten idempotent.

## Provider-Registry

Provider werden ueber `TowerDNS\Application\Provider\ProviderRegistry` injiziert. Anwendungs-Services (z. B. `DnsManagementService`) waehlen den Provider pro Aufruf ueber dessen stabile ID (`desec`, `powerdns`, `cloudflare`, `inwx`). Es gibt keine harte Kopplung an einen einzelnen Anbieter.

## Capability-orientiertes Modell

Provider werden nicht auf einen kleinsten gemeinsamen Nenner reduziert. Jeder Adapter deklariert in seiner `capabilityMap()`, welche `Capability::*`-Konstanten er liefert. `DnsManagementService` lehnt Workflows ab, fuer die ein Provider keine Capability deklariert (`CapabilityException`).

Beispiele:
- `zone.create`, `zone.delete`
- `record.create`, `record.update`, `record.comment`
- `dnssec.status.read`, `dnssec.auto_managed`
- `dnssec.action.execute`, `dnssec.key.list`, `dnssec.key.rollover`
- `provider.credentials.manage`

## DNSSEC

DNSSEC ist Teil des Domain-Modells (`DnssecProfile`, `DnssecState`) und kein optionales Add-on:

| Provider   | DNSSEC-Charakteristik                                                                                  |
|------------|--------------------------------------------------------------------------------------------------------|
| deSEC      | vollautomatisch verwaltet (`dnssec.auto_managed = true`), nur Status- und DS-Lesezugriff               |
| PowerDNS   | direkt steuerbar via `cryptokeys`-API: enable/disable, key add/remove, activate/deactivate (Rollover) |
| Cloudflare | provider-managed, aber per API ein-/ausschaltbar; DS-Auslesung moeglich                                |
| INWX       | DS-Submission und Schluesselsicht auf Registry-Ebene moeglich                                          |

Workflows fragen `DnssecProfile::$features` ab, um pro Zone passende Aktionen anzubieten.

## Rechtepruefung

Rechte werden zentral in der Application-Schicht (`AuthorizationService`) anhand von `Permission`-Werten geprueft. Jeder oeffentliche Service-Aufruf beginnt mit einer `assert(...)`-Pruefung, bevor Provider oder Domain-Logik beruehrt werden.

## Benutzer, Profil und Accounts

`User` ist die Login-Identitaet; Ressourcen gehoeren ausschliesslich einem `Account`. `AccountMembership` verbindet beide. `UserLifecycleService` legt bei der Benutzererstellung transaktional einen persoenlichen Account mit Owner-Membership und ResourceLimits an. Der Fresh Install erzeugt fuer den ersten Administrator zusaetzlich zum benannten Organisations-Account einen persoenlichen Account. Bestandsbenutzer ohne diesen Container erhalten ihn bei der naechsten authentifizierten Anfrage idempotent. Bei Benutzerloeschung verhindern weitere Mitglieder im persoenlichen Account, eigene Ressourcen oder noch gehaltene Organisations-Accounts eine stille Loeschung.

Ohne Schema-Migration wird `AccountKind` derzeit ueber den reservierten Slug `personal-<user-id>` bestimmt; andere Accounts sind Organisationen. Neue Organisations-Slugs duerfen dieses Praefix nicht verwenden. Eine spaetere explizite `accounts.type`-Spalte ist fuer eine Migrationsphase vorgesehen. `ActiveAccount` bleibt nur Scope und verleiht keine Rechte.

Das bestehende `users.locale` speichert eine der unterstuetzten UI-/Regional-Locale-Kombinationen `en-GB` oder `de-DE`; die Sprache wird daraus abgeleitet. Der Translator setzt sie fuer jede Anfrage neu und faellt auf `en-GB` zurueck. Eine getrennte Sprachpraeferenz, Zeitzone sowie optionale Kontakt- und ERP-/CRM-Personenfelder benoetigen eine spaetere Schemaerweiterung. Passwort-Hash, TOTP-Secrets und andere Zugangsdaten sind nicht Teil des Profilmodells. Profil- und Zugangsdaten-Routen sind waehrend Impersonation gesperrt.

## Erweiterung

Neuen Provider hinzufuegen:

1. Neuen Namespace `TowerDNS\Infrastructure\Provider\<Name>` anlegen.
2. Adapter-Klasse `<Name>Provider extends AbstractDnsProvider` erstellen und `capabilityMap()` deklarieren.
3. `DnsProviderInterface` implementieren.
4. Adapter-Instanz beim Bootstrap an die `ProviderRegistry` uebergeben.

## Sicherheits-Subsystem

### Authentifizierung

- **Passwort** mit bcrypt (`password_hash`), Policy-gesteuert via `PasswordPolicy` (Mindestlaenge, zxcvbn-Score).
- **TOTP** (`TotpService`): SHA-512, 8 Stellen, 64-Byte-Secret, Aegis/FreeOTP+-kompatibel. Google Authenticator ist wegen SHA-1-Beschraenkung inkompatibel.
- **FIDO2/WebAuthn** (`WebAuthnService`): Resident-Key-Support, ES256/RS256, challenge-basierte Login-Ceremony.

### HIBP-Integration (Have I Been Pwned)

`HibpRangePasswordChecker` implementiert `BreachedPasswordCheckerInterface` via k-Anonymity Range-API (kein Klartextpasswort verlaesst das System). PSR-18 als Client-Interface; Guzzle wird nur in Kompositionsstellen (`ContainerFactory`, Console-Commands) als Konkrete eingesetzt. Im Air-gapped-Betrieb oder wenn HIBP in `system_settings` deaktiviert ist, wird automatisch `NullBreachedPasswordChecker` gewaehlt.

### Konfiguration: Bootstrap vs. Runtime

| Quelle           | Enthaelt                                               |
|------------------|--------------------------------------------------------|
| TOML-Config      | DB-Verbindung, `encryption_key`, Hostname, App-Name    |
| `system_settings`| Passwort-Policy, HIBP-Flags, zukuenftige Runtime-Werte |

TOML enthaelt ausschliesslich Bootstrap-Parameter, die vor jeder DB-Verbindung benoetigt werden. Alle ueber die Admin-UI aenderbaren Einstellungen werden in der Tabelle `system_settings` gespeichert (`DbalSystemSettingsRepository`, per-Request-Cache).

### Backup, Restore und Key Recovery

Ein Restore ist nur vollstaendig, wenn **beide** Bestandteile gemeinsam gesichert und wiederhergestellt werden:

1. **Datenbank** (SQLite-Datei bzw. `pg_dump`/`mysqldump`-Export je nach Treiber).
2. **`configs/config.local.toml`** (und, falls vorhanden, `configs/database.toml`/`configs/providers.toml`), insbesondere `security.encryption_key`.

Provider-Credentials und TOTP-Secrets werden mit `security.encryption_key` reversibel verschluesselt in der DB abgelegt (`CredentialService`). Ein DB-Dump allein ist damit **nicht** wiederherstellbar nutzbar: ohne den passenden Schluessel bleiben alle verschluesselten Werte dauerhaft unlesbar. Beide Artefakte muessen daher als zusammengehoerige Einheit gesichert, transportiert und aufbewahrt werden (z.B. gemeinsam verschluesseltes Backup-Archiv, getrennt von der Produktionsumgebung).

`ContainerFactory` und `InstallCommand` erzeugen `encryption_key` **ausschliesslich einmalig** beim Fresh Install. Es gibt bewusst keinen Laufzeit-Fallback, der bei fehlendem oder ungueltigem Schluessel automatisch einen neuen erzeugt (`ContainerFactory::create()` wirft stattdessen eine `RuntimeException`) — ein automatisch neu erzeugter Schluessel wuerde alle bestehenden verschluesselten Daten unbemerkt und permanent unlesbar machen. Geht der Produktions-Schluessel verloren und existiert kein Backup davon, sind Provider-Credentials und TOTP-Secrets nicht wiederherstellbar; betroffene ProviderAccounts und TOTP-Registrierungen muessen dann manuell neu angelegt werden.

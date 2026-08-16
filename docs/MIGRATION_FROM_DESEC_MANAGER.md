# Migration von desec-manager zu TowerDNS

## Status

TowerDNS ist der offizielle technische Nachfolger von [desec-manager](https://github.com/DiamantTh/desec-manager). Das Vorgaengerprojekt wird nicht mehr weiterentwickelt; relevante Bausteine wurden in die neue Architektur ueberfuehrt.

## Migrationsprinzipien

- **Keine reine Umbenennung.** TowerDNS hat eigene Identitaet, Architektur und Provider-Schicht.
- **deSEC ist nur noch ein Provider.** Die UI und der Core sind providerneutral; deSEC-spezifische Endpunkte werden ausschliesslich im Adapter beruehrt.
- **Capability-orientiert statt feature-flat.** Provider werden nicht auf einen kleinsten gemeinsamen Nenner reduziert.
- **Strenges RBAC.** Sensible Bereiche (DNSSEC, Provider-Credentials, IAM, Systemeinstellungen) haben eigene Permissions.
- **Lizenz und Herkunft.** TowerDNS steht unter **AGPL-3.0-or-later** (`LICENSE`) und uebernimmt damit konsequent die Copyleft-Linie des Vorgaengerprojekts. SPDX-Identifier: `AGPL-3.0-or-later`. Eine Relizenzierung unter einer permissiveren Lizenz (MIT/BSD/Apache) ist ausgeschlossen.

## Zuordnung alt -> neu

| desec-manager (`App\...`)              | TowerDNS                                                                            |
|----------------------------------------|--------------------------------------------------------------------------------------|
| `App\DeSEC\DeSECClient`                | `TowerDNS\Infrastructure\Provider\DeSEC\DeSECApiClient`                              |
| `App\DeSEC\DeSECException`             | `TowerDNS\Infrastructure\Provider\DeSEC\DeSECApiException`                           |
| `App\Service\DNSService` (deSEC-fest)  | `TowerDNS\Application\Services\DnsManagementService` (provider-neutral)              |
| `App\Service\AuthorizationService`     | `TowerDNS\Application\Services\AuthorizationService` (Permission-Enum-basiert)      |
| `App\Security\DomainValidator`         | `TowerDNS\Application\Validation\DnsNameValidator` (auf DNS reduziert, IDN-tauglich) |
| `App\Entity\User` / Rollenrepository   | `TowerDNS\Domain\Auth\User` / `Role` / `Permission`                                  |

In TowerDNS eigenstaendig neu modelliert (nicht aus desec-manager portiert):
- **WebAuthn** (`WebAuthnService`, `web-auth/webauthn-lib`): Resident-Key, ES256/RS256, vollstaendige Registration- und Authentication-Ceremony
- **TOTP** (`TotpService`, `spomky-labs/otphp`): SHA-512, 8 Stellen, 64-Byte-Secret
- **Mailer** (`MailService`, Symfony Mailer): Passwort-Reset, zukuenftige Benachrichtigungen
- **Svelte-UI**: eigenstaendige Skeleton/Svelte-Oberflaeche ohne Twig- oder desec-manager-Abhaengigkeit
- **Passwort-Policy + HIBP** (`PasswordPolicy`, `HibpRangePasswordChecker`): zxcvbn-Score, optionale HIBP Range-API, eigene Implementierung ohne externe Client-Libs

## Zielprovider

- deSEC (migriert, funktional)
- PowerDNS (Adapter inkl. nativer DNSSEC-Steuerung ueber `cryptokeys`-API)
- Cloudflare (Adapter-Skelett mit korrekter Capability-Deklaration)
- INWX (Adapter-Skelett mit korrekter Capability-Deklaration)

## DNSSEC-Migration

- `DnssecProfile` und `DnssecState` sind Teil des Domain-Modells.
- deSEC: vollautomatische DNSSEC-Verwaltung wird ueber `dnssec.auto_managed` ausgewiesen; manuelle Aktionen sind bewusst deaktiviert.
- PowerDNS: imperatives Key-Management (`enable`, `disable`, `key.add`, `key.remove`, `key.activate`, `key.deactivate`) wird direkt an die PDNS-API angebunden.
- Cloudflare/INWX: Capabilities sind deklariert, konkrete API-Bindung folgt im jeweiligen Adapter.

## Naechste Schritte

- Cloudflare- und INWX-Adapter ausimplementieren (Skelette vorhanden, Capabilities deklariert).
- Importpfad fuer bestehende deSEC-Bestaende aus desec-manager-Datenbanken bereitstellen.
- Runtime-Settings (App-Name, Force-HTTPS, Theme, Mailer) aus TOML in `system_settings` migrieren.

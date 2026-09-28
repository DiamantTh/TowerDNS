# Rollen- und Berechtigungsmodell

## Sicherheitsentscheidungen

TowerDNS trennt Authentifizierung, globale Autorisierung, Ressourcenscope und Provider-Fähigkeiten:

1. `SessionSecurity` verwaltet MFA-Pending- und angemeldete Sessions (Rotation, Ablauf); `TowerDNSSessionAuthentication` adaptiert das auf `Mezzio\Authentication\AuthenticationInterface`.
2. `AuthenticationMiddleware` lädt die aktive Identität bei jedem Request neu aus dem Repository. Sie stellt dieselbe Identität als Mezzio-`UserInterface` und als kanonisches `TowerDNS\Domain\Auth\User`-Attribut bereit. Im Admin-Switch wird die Mezzio- und Domain-Identität gemeinsam auf den Zielbenutzer umgestellt.
3. `AuthorizationService` prüft globale Rechte mittels des vorhandenen Laminas-RBAC-Verhaltens. `PermissionService` prüft zusätzlich aktiven Account, Zugehörigkeit der `ManagedZone` zum Account sowie Account-/Zonenmitgliedschaften.
4. Der Application-Service prüft die konkrete Aktion; DNS-Workflows prüfen zusätzlich die Capability des ausgewählten Providers.

Die Mezzio-Session-Authentifizierungsadapter werden nicht parallel verwendet: sie würden eigene Credential-/Sessionzustände neben TowerDNS-MFA und `SessionSecurity` schaffen. `mezzio/mezzio-authorization-rbac` wird ebenfalls nicht eingesetzt. Seine rollen-/routenbasierte Prüfung kann den Account-/Zonen-Scope und die Provider-Capability nicht ersetzen; ein zusätzlicher Routengate würde dieselbe Fachautorisierung doppelt abbilden. `laminas/laminas-permissions-rbac` bleibt die einzige Rollen-Engine.

`TowerDNSAuthenticatedUser::getDetails()` gibt keine Profildaten, MFA-Merkmale, Secrets oder Berechtigungsdetails zurück. Die Mezzio-Rollenliste ist lediglich die Identitätsdarstellung; sie ist keine Berechtigungsentscheidung. `RequireAuthMiddleware` akzeptiert geschützte Requests nur, wenn Mezzio- und TowerDNS-Identität dieselbe User-ID enthalten.

## Globale Rollen und Ressourcenscope

Direkte `User.roles` sind globale Systemrollen. Sie sind nicht dasselbe wie `TeamRole`-Memberships. `TeamRole` (`OWNER`, `ADMIN`, `DNS_MANAGER`, `VIEWER`, `AUDITOR`) liefert einen positiven Permission-Grant innerhalb des Accounts beziehungsweise der ManagedZone, der die Membership zugeordnet ist. Account- und Zonenmitgliedschaften können sich ergänzen; es gibt keine stillschweigenden Deny-Regeln. `ActiveAccount` ist nur die gewählte Scope-Auswahl und verleiht selbst keine Rechte.

Die historischen globalen Seed-Rollen `viewer`, `editor` und `dnssec_op` enthalten DNS-Permission-IDs, erzeugen aber **keine Account- oder Zonenmitgliedschaft** und damit keinen Zugriff auf beliebige DNS-Ressourcen. Die gleichnamige `TeamRole::VIEWER` ist dagegen ein Scoped-Grant. Globale Systemrechte werden dort ausgewertet, wo TowerDNS sie ausdrücklich systemweit prüft. Bestehende Seed-Rollen werden bei Schema-Seedläufen nicht automatisch umgeschrieben oder gelöscht.

`SYSTEM_ACCOUNTS_ACCESS` ist kein globales `ACTION_ALL`: es kann ausschließlich lesenden Zugriff auf registrierte aktive Accounts und dazugehörige ManagedZones ergänzen (`ACCOUNT_READ`, Zonen-/Record-Lesen und DNSSEC-Status). Schreib-, Credential-, DNSSEC-Ausführungs-, IAM- und Ownership-Aktionen benötigen weiterhin ihren jeweiligen Grant. Ausschließlich die eingebaute Rolle mit `id=superadmin` und `isBuiltIn=true` besitzt den bewusst globalen Operator-Bypass; eine selbst angelegte Rolle gleichen Namens genügt nicht.

## Rollenzuweisung und IAM

- Benutzerpflege benötigt `USER_MANAGE`.
- Globale Rollenzuweisungen benötigen **beides**, `USER_MANAGE` und `ROLE_MANAGE`.
- Rollen können nur Permissions enthalten, die der handelnde effektive Benutzer selbst aktuell besitzt. Die eingebaute `superadmin`-Rolle darf ausschließlich ein aktiver Benutzer mit der echten eingebauten Superadmin-Rolle vergeben.
- Systemrollen sind unveränderlich. Der letzte aktive Superadmin kann weder deaktiviert noch gelöscht noch seiner eingebauten Rolle beraubt werden.
- Diese Regeln liegen in `IamAdministrationService`, nicht nur im Formular. IAM-Mutationen werden innerhalb einer Transaktion an einer gemeinsamen Sperrstelle serialisiert, erneut gegen den aktiven Actor geprüft und mit vorher/nachher-Rollenstand auditiert. Sicherheitsrelevant verweigerte IAM-Änderungen werden mit Actor, effektivem Benutzer, Ziel, Grundcode und nicht geheimen Versuchsdaten protokolliert.
- Eine selbstbewirkte Erhöhung über `USER_MANAGE` allein ist damit nicht zulässig. Ein Delegierender kann Rechte höchstens innerhalb seines eigenen effektiven Permission-Rahmens vergeben.

Der Built-in-Superadmin-Status ist eine explizite Betreiberrolle. Es gibt keine persistierte Rollenvererbungshierarchie; Laminas-RBAC wird hier als Permission-Prüfer für die aufgelösten Rollen eingesetzt.

## Admin-Switch / Impersonation

Nur der aktive eingebaute Superadmin darf einen Switch starten. Der Zielbenutzer muss aktiv und explizit gewählt sein; Switch-Chaining wird abgelehnt. Ein optionaler Account-Scope muss für den Zielbenutzer bereits zugänglich sein.

Während des Switches bestimmen ausschließlich die bei jedem Request neu geladenen Rechte, Account-/Zonenmitgliedschaften und die aktive Auswahl des Zielbenutzers dessen Handlungen. Die ursprüngliche Identität dient ausschließlich zum Nachweis des Switch-Kontexts, Audit und sicheren Beenden. Sie wird nicht mit den Zielrechten vereinigt. Wird der Switch ungültig (Ablauf, Ziel deaktiviert, Mitgliedschaft entzogen oder Actor verliert die eingebaute Superadmin-Rolle), wird er beendet, protokolliert und der gerade laufende Request mit 403 abgebrochen; er wird nicht noch unter den ursprünglichen Rechten ausgeführt. Der nächste Request läuft nach dem beendeten Switch wieder als ursprünglicher Benutzer mit dessen aktuellen Rechten. Das reguläre Beenden erfordert die gebundene Switch-Session und führt keine Fachaktion unter ursprünglichen Rechten aus. `/profile` und dessen Unterseiten sind während eines Switches gesperrt; administrative Passwortänderungen an fremden Profilen sind ebenfalls gesperrt. Audit-Einträge halten Original-Actor, effektiven Benutzer und Switch-ID getrennt fest.

## MFA, Sessions und Schlüssel

Passwortlogin verwendet aktuell Argon2id; gültige ältere Hashes (einschließlich bcrypt) werden beim erfolgreichen Login auf Argon2id aktualisiert. Ein Dummy-Hash reduziert zeitbasierte E-Mail-Enumeration. Bei eingerichteter TOTP- oder WebAuthn-MFA ist die Session zunächst nur MFA-pending und `AuthenticationMiddleware` akzeptiert sie nicht als angemeldet. Login/MFA regeneriert die Session-ID. `SessionSecurity` erzwingt derzeit 60 Minuten Inaktivitäts- und 8 Stunden absolute Laufzeit. Session-Cookies sind `HttpOnly`, `SameSite=Lax`; `Secure` hängt von `force_https` beziehungsweise der Session-Konfiguration ab. Betreiber müssen TLS und eine korrekte Trusted-Proxy-Konfiguration aktivieren, wenn TLS vor PHP terminiert.

TOTP und WebAuthn sind verfügbar. Für Benutzer-/Rollenänderungen (einschließlich Rollenvergabe, Status, Löschen und administrativem Passwortsetzen) sowie den Beginn eines Admin-Switches verlangt TowerDNS eine frische, aktions- und zielgebundene Bestätigung mit eingerichtetem TOTP oder Passkey. Für WebAuthn wird User Verification `required` verlangt. Es gibt keinen Passwort- oder UI-Bypass, wenn kein zweiter Faktor eingerichtet ist; der Betreiber muss zunächst über das Profil einen Faktor registrieren. Das Passwort muss dafür innerhalb der letzten zehn Minuten bestätigt worden sein. Step-up-Nachweise sind fünf Minuten gültig und an den effektiven Benutzer, Aktion, Ziel und gegebenenfalls Switch-Kontext gebunden. Eine Datenbank-Nonce-Tabelle stellt die Einmalverwendung auch bei parallel laufenden PHP-Requests sicher. Die Schemaänderung ist Migration `Version20260927000100` und muss vor solchen Aktionen über den kontrollierten Upgrade-Weg ausgeführt sein.

Eine erneute Step-up-Bestätigung wird derzeit bei jeder qualifizierten Aktion verlangt; TowerDNS speichert keine allgemeine „MFA kürzlich bestätigt“-Freigabe, die auf andere Aktionen übertragbar wäre. Die eigentliche Berechtigung und Zielprüfung wird danach weiterhin zentral im Application-Service ausgeführt. Admin-Switch darf nicht unter laufender Impersonation gestartet werden; bei Rollenänderungen im Switch gilt ausschließlich das Recht des effektiven Zielbenutzers.

Es gibt derzeit keinen eingehenden TowerDNS-API-Key-Authentifizierungsfluss. Die Profilausgabe erstellt daher keine neuen Schlüssel mehr; bestehende Datensätze bleiben sichtbar und können widerrufen werden, gewähren aber keinen API-Zugriff. Die Datenbankstruktur bleibt zur Erhaltung bestehender Installationen bestehen. Ausgehende DNS-Provider-APIs sind davon unabhängig weiterhin Teil der Provideradapter. Eine lokale CLI arbeitet innerhalb der Betriebssystem-/Deployment-Vertrauensgrenze und ist kein benutzergebundenes API-Token.

## Provider-Capability und Ablauf

```php
$service->createZone($user, $accountId, 'example.com');
// 1. AuthorizationService/PermissionService: Aktion + Account-Scope
// 2. ManagedZone-/Account-Zuordnung prüfen
// 3. konkrete Provider-Capability prüfen
// 4. Eingaben validieren
// 5. Adapter-Aufruf
```

UI-Ausblendung, aktive Account-Auswahl und HTTP-Routen sind keine Sicherheitsgrenzen. HTTP-Handler, CLI und künftige Adapter müssen dieselben Application-Services verwenden.

## Offene, konkret begrenzte Punkte

- Die Mehrprozess-Serialisierung muss auf den tatsächlich eingesetzten MariaDB-/PostgreSQL-Versionen zusätzlich unter paralleler Last ausgeführt werden; PHPUnit führt dafür einen Fork-Test gegen SQLite und gegen explizit konfigurierte Integrationsdatenbanken aus.
- Passkey-Step-up benötigt einen echten kompatiblen Browser/Authenticator für einen Ende-zu-Ende-Ceremony-Nachweis; automatisierte TOTP-HTTP-Tests und WebAuthn-Options-/Handlerprüfungen ersetzen diesen Hardware-/Browsernachweis nicht.
- Reale Shared-Hosting-/PHP-FPM-Session- und Proxybedingungen sind nicht durch die lokale Apache-Compose-Umgebung bewiesen.
- Keine eingehende TowerDNS-API-Key-Authentifizierung; bestehende Schlüssel sind inert und nur widerrufbar.
- Globale DNS-Seed-Rollen sind aus Kompatibilitätsgründen weiter vorhanden, aber kein Ersatz für scoped TeamRole-Memberships.
- Datenbankseitige Step-up-Nonce-Sicherung setzt voraus, dass Schema-Migrationen vor Nutzung der geschützten Aktionen ausgeführt wurden.

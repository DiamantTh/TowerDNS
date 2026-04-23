# Migration von desec-manager zu TowerDNS

## Status

TowerDNS ist der offizielle technische Nachfolger von desec-manager.
Das alte Projekt dient als Migrationsbasis und wird als Vorgaenger markiert.

## Migrationsprinzipien

- Keine reine Umbenennung: neue Identitaet und neue Zielarchitektur
- Entkopplung alter deSEC-spezifischer Abhaengigkeiten
- Uebernahme sinnvoller Bausteine nur nach Anpassung an neue Layer
- konsequente Provider-Abstraktion mit Capabilities

## Erste Ueberfuehrung

Die deSEC-Integration wird als erste migrierte Provider-Implementierung gefuehrt.
Direkte Kopplungen von UI oder Core an deSEC-spezifische API-Endpunkte werden entfernt.

## Zielprovider

- deSEC
- PowerDNS
- Cloudflare
- INWX

## DNSSEC-Migration

- DNSSEC bleibt kein optionales Add-on, sondern eigener Fachbereich
- Provider mit tiefer DNSSEC-Steuerung behalten diese Moeglichkeiten
- PDNS DNSSEC-Funktionen werden ueber direkten API-Adapter integriert

## Technische Angleichung

- Composer und PSR-4 als verbindliche Basis
- Vereinheitlichte Namespace- und Ordnerstruktur
- klare Trennung von Domain, Application, Infrastructure, UI

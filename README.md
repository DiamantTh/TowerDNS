# TowerDNS

TowerDNS ist ein providerunabhaengiges, DNS-zentriertes Management-Panel und der offizielle technische Nachfolger von desec-manager.

## Vorgaenger und Migration

Vorgaengerprojekt:
- Selfhosted: https://git.diath.systems/DiamantTh/desec-manager
- GitHub: https://github.com/DiamantTh/desec-manager

Das bisherige Projekt desec-manager dient als Migrationsbasis. Die Weiterentwicklung erfolgt ausschliesslich in diesem Repository.

## Zielbild

TowerDNS ist kein reiner Wrapper fuer einen einzelnen Anbieter. Das Projekt stellt eine gemeinsame Kernlogik fuer DNS-Verwaltung bereit und bindet Provider ueber eine abstrahierte, capability-orientierte Schnittstelle an.

Schwerpunkte:
- Verwaltung von Zonen, Records, TTL, Kommentaren, Tags und Metadaten
- Striktes Rollen- und Rechtesystem mit zentraler Pruefung in der Application-Schicht
- DNSSEC als eigener fachlicher Bereich, nicht als Sonderbehandlung am Rand
- Provideradapter fuer deSEC, PowerDNS, Cloudflare und INWX

## Architektur

Layer:
- UI: einheitliche Oberflaeche ohne direkte Anbieterlogik
- Application/Core: providerneutrale Workflows, Validierung, Normalisierung und Rechtepruefung
- Provider-Abstraktion: gemeinsamer Vertrag und capability-orientiertes Modell
- Provider-Implementierungen: deSEC, PDNS, Cloudflare, INWX als getrennte Adapter
- Domain-Modell: kanonische Darstellung von DNS-Objekten inklusive DNSSEC

## DNSSEC-Grundsatz

DNSSEC wird fachlich vollstaendig modelliert:
- Provider mit einfacher/automatischer DNSSEC-Abwicklung werden korrekt abgebildet
- Provider mit direkter DNSSEC-Steuerung bleiben detailliert steuerbar
- PowerDNS DNSSEC-Funktionen werden direkt an die PDNS-API angebunden

## Rollen und Rechte

TowerDNS fuehrt ein feingranulares Berechtigungssystem ein:
- getrennte Rechte fuer Lesen, Anlegen, Aendern, Loeschen
- getrennte Rechte fuer DNSSEC-Aktionen
- getrennte Rechte fuer Provider-Credentials, Rollen/User und globale Admin-Funktionen

Rechtepruefungen sind zentral im Core und nicht nur in der UI.

## Technische Basis

- PHP + Composer (PSR-4)
- saubere Layer-Struktur unter src/Domain, src/Application, src/Infrastructure, src/UI
- konsistente Namespaces, DTOs, Policies, Services und Provider-Adapter

## Aktueller Stand

Diese Initialversion stellt die neue Zielstruktur und den Migrationsrahmen bereit:
- providerneutrales Vertragsmodell
- kanonische DNS- und DNSSEC-Modelle
- RBAC-Basis
- erste migrierte deSEC-Providerstruktur
- vorbereitete Adapter fuer PDNS, Cloudflare und INWX

## Naechste Schritte

- API-Clients pro Provider implementieren
- Persistenz und User-/Role-Storage anbinden
- Endpunkte/UI fuer Zonen, Records, DNSSEC und RBAC ausbauen
- Migrationspfad aus desec-manager in produktive Datenfluesse ueberfuehren
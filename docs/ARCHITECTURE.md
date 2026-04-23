# TowerDNS Architektur

## Ziel

TowerDNS ist ein DNS-Management-Panel mit gemeinsamer Kernlogik und austauschbaren Provider-Adaptern.

## Layer-Modell

1. UI
- Einheitliche Oberflaeche fuer Zonen, Records, Metadaten, Rollen, Rechte und DNSSEC
- Keine direkte API- oder Provider-Logik

2. Application
- Orchestrierung von Workflows
- Validierung und Normalisierung
- zentrale Rechtepruefung
- Nutzung von Provider-Interfaces statt direkter Adapterzugriffe

3. Domain
- Kanonische Modelle fuer DNS und RBAC
- Fachregeln und semantische Zustandsobjekte

4. Infrastructure
- Provider-Adapter fuer deSEC, PDNS, Cloudflare, INWX
- Konfiguration, externe API-Clients, Mapping intern/extern

## Capability-orientiertes Modell

Provider werden nicht auf kleinsten gemeinsamen Nenner reduziert.
Stattdessen beschreibt jeder Adapter seine Faehigkeiten ueber Capabilities.

Beispiele:
- zone.create
- record.comment
- dnssec.status.read
- dnssec.key.rollover
- provider.credentials.manage

Die Application-Schicht entscheidet anhand dieser Capabilities ueber verfuegbare Workflows.

## DNSSEC

DNSSEC ist ein eigener Fachbereich im Domain-Modell:
- DNSSEC-Status
- DNSSEC-Aktionen
- Provider-spezifische Detailgrade

Provider mit eingeschraenkter DNSSEC-Steuerung liefern nur die unterstuetzten Aktionen.
Provider mit tiefer Steuerung (z. B. PDNS) stellen erweiterte DNSSEC-Funktionen bereit.

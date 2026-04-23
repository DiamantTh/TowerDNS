# TowerDNS Architektur

## Ziel

TowerDNS ist ein DNS-Management-Panel mit gemeinsamer Kernlogik und austauschbaren, capability-orientierten Provider-Adaptern. Es ist der offizielle technische Nachfolger von [desec-manager](https://github.com/DiamantTh/desec-manager); deSEC ist nur noch ein Provider unter mehreren.

## Layer-Modell

```
src/
  Domain/         kanonische DNS- und RBAC-Modelle
  Application/    Workflows, Validierung, Capabilities, Rechtepruefung
  Infrastructure/ Provider-Adapter (deSEC, PowerDNS, Cloudflare, INWX)
  UI/             (folgt) Panel ohne direkte Provider-Logik
```

1. **UI** verwendet ausschliesslich Application-Services. Sie kennt keine Provider-spezifischen Endpunkte.
2. **Application** orchestriert Workflows, normalisiert Eingaben (`DnsNameValidator`, `RecordValidator`), prueft Rechte zentral (`AuthorizationService`) und prueft Provider-Faehigkeiten (`Capability` + `ProviderCapabilitySet`).
3. **Domain** stellt das kanonische DNS-Modell (`Zone`, `Record`, `RecordType`, `DnssecProfile`, `DnssecState`) und das RBAC-Modell (`User`, `Role`, `Permission`).
4. **Infrastructure/Provider** kapselt jede externe API in einem eigenen Adapter unter `src/Infrastructure/Provider/<Provider>/`.

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

## Erweiterung

Neuen Provider hinzufuegen:

1. Neuen Namespace `TowerDNS\Infrastructure\Provider\<Name>` anlegen.
2. Adapter-Klasse `<Name>Provider extends AbstractDnsProvider` erstellen und `capabilityMap()` deklarieren.
3. `DnsProviderInterface` implementieren.
4. Adapter-Instanz beim Bootstrap an die `ProviderRegistry` uebergeben.

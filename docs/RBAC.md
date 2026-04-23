# Rollen- und Berechtigungsmodell

## Grundprinzip

Rechte werden explizit, granular und zentral geprueft.
Ein Benutzer erhaelt keine impliziten Vollrechte durch reinen Provider- oder Zonenzugriff.

## Rechtekategorien

- Zonen: read, create, update, delete
- Records: read, create, update, delete
- DNSSEC: status.read, action.execute
- Provider: credentials.manage, config.manage
- Benutzer/Rollen: users.manage, roles.manage
- System: settings.manage

## Beispielrechte

- zone.read
- zone.create
- zone.delete
- record.read
- record.create
- record.update
- record.delete
- dnssec.status.read
- dnssec.action.execute
- provider.credentials.manage
- user.manage
- role.manage
- system.settings.manage

## Durchsetzung

Die Pruefung erfolgt in der Application-Schicht ueber eine zentrale AuthorizationService-Komponente.
UI entscheidet nur ueber Darstellung, nicht ueber Autorisierung.
